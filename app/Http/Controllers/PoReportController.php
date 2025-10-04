<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Exports\PoItemsExport;
use Maatwebsite\Excel\Facades\Excel;

class PoReportController extends Controller
{
    /** Halaman report (tabel) */
    public function index(Request $request)
    {
        // terima q terenkripsi
        if ($request->has('q')) {
            try {
                $data = Crypt::decrypt($request->query('q'));
                if (is_array($data)) $request->merge($data);
            } catch (DecryptException $e) {
                return redirect()->route('dashboard')->withErrors('Link Report tidak valid.');
            }
        }

        $werks   = $request->query('werks');
        $auart   = $request->query('auart');
        $compact = $request->boolean('compact', true);
        $show    = filled($werks) && filled($auart);

        // mapping untuk pills
        $mapping = DB::table('maping')
            ->select('IV_WERKS', 'IV_AUART', 'Deskription')
            ->orderBy('IV_WERKS')->orderBy('IV_AUART')
            ->get()
            ->groupBy('IV_WERKS')
            ->map(fn($g) => $g->unique('IV_AUART')->values());

        $selectedDescription = '';
        if ($werks && $auart) {
            $selectedDescription = DB::table('maping')
                ->where('IV_WERKS', $werks)->where('IV_AUART', $auart)
                ->value('Deskription') ?? '';
        }

        // auto pilih default auart jika hanya plant
        if ($request->filled('werks') && !$request->filled('auart') && !$request->has('q')) {
            $types = collect($mapping[$werks] ?? []);
            $default = $types->first(function ($row) {
                $d = strtolower((string)$row->Deskription);
                return str_contains($d, 'kmi') && str_contains($d, 'export')
                    && !str_contains($d, 'replace') && !str_contains($d, 'local');
            }) ?? $types->first(function ($row) {
                $d = strtolower((string)$row->Deskription);
                return str_contains($d, 'export') && !str_contains($d, 'replace') && !str_contains($d, 'local');
            }) ?? $types->first();

            if ($default) {
                $payload = ['werks' => $werks, 'auart' => $default->IV_AUART, 'compact' => 1];
                return redirect()->route('po.report', ['q' => Crypt::encrypt($payload)]);
            }
        }

        $rows = collect();
        if ($show) {
            // Parser tanggal aman
            $safeEdatu = "COALESCE(
                STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
                STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
            )";
            $safeEdatuInner = "COALESCE(
                STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2_inner.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
                STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2_inner.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
            )";

            // Query overview customer (mirip dashboard)
            $rows = DB::table('so_yppr079_t2 as t2')
                ->leftJoin(DB::raw('(
                    SELECT t2_inner.KUNNR, SUM(t1.TOTPR) AS TOTAL_TOTPR
                    FROM so_yppr079_t2 AS t2_inner
                    LEFT JOIN so_yppr079_t1 AS t1
                      ON TRIM(CAST(t1.VBELN AS CHAR)) = TRIM(CAST(t2_inner.VBELN AS CHAR))
                    WHERE t2_inner.IV_WERKS_PARAM = ' . DB::getPdo()->quote($werks) . '
                      AND t2_inner.IV_AUART_PARAM  = ' . DB::getPdo()->quote($auart) . '
                      AND ' . $safeEdatuInner . ' < CURDATE()
                    GROUP BY t2_inner.KUNNR
                ) AS totpr_sum'), 't2.KUNNR', '=', 'totpr_sum.KUNNR')
                ->select(
                    't2.KUNNR',
                    DB::raw('MAX(t2.NAME1) AS NAME1'),
                    DB::raw('COALESCE(MAX(totpr_sum.TOTAL_TOTPR), 0) AS TOTPR'),
                    DB::raw('MAX(t2.WAERK) AS WAERK'),
                    DB::raw('COUNT(DISTINCT TRIM(CAST(t2.VBELN AS CHAR))) AS SO_COUNT'),
                    DB::raw("SUM(CASE WHEN {$safeEdatu} < CURDATE() THEN 1 ELSE 0 END) AS SO_LATE_COUNT"),
                    DB::raw("ROUND((SUM(CASE WHEN {$safeEdatu} < CURDATE() THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT TRIM(CAST(t2.VBELN AS CHAR))), 0)) * 100, 2) AS LATE_PCT")
                )
                ->where('t2.IV_WERKS_PARAM', $werks)
                ->where('t2.IV_AUART_PARAM', $auart)
                ->groupBy('t2.KUNNR')
                ->orderBy('NAME1')
                ->paginate(25)->withQueryString();
        }

        return view('po_report.po_report', [
            'mapping'              => $mapping,
            'selected'             => ['werks' => $werks, 'auart' => $auart],
            'selectedDescription'  => $selectedDescription,
            'rows'                 => $rows,
            'compact'              => $compact,
            'show'                 => $show,
        ]);
    }

    /** Export item terpilih ke PDF/Excel */
    public function exportData(Request $request)
    {
        // Validasi dasar (tanpa memaksa integer di sini; kita sanitasi manual)
        $request->validate([
            'item_ids'    => 'required|array|min:1',
            'export_type' => 'required|string|in:pdf,excel',
            'werks'       => 'required|string',
            'auart'       => 'required|string',
        ]);

        // Sanitasi ID → hanya digit
        $ids = collect($request->input('item_ids', []))
            ->map(fn($v) => (int)preg_replace('/\D+/', '', (string)$v))
            ->filter(fn($v) => $v > 0)
            ->unique()->values()->all();

        if (empty($ids)) {
            return back()->withErrors('Tidak ada item yang valid untuk diekspor.');
        }

        $werks      = $request->input('werks');
        $auart      = $request->input('auart');
        $exportType = $request->input('export_type');

        // Ambil item by id + info header (PO, SO, Customer)
        $items = DB::table('so_yppr079_t1 as t1')
            ->leftJoin('so_yppr079_t2 as t2', 't1.VBELN', '=', 't2.VBELN')
            ->select(
                't1.id',
                't1.VBELN as SO',
                't2.BSTNK as PO',
                't2.NAME1 as CUSTOMER',
                DB::raw("TRIM(LEADING '0' FROM t1.POSNR) AS POSNR"),
                DB::raw("CASE WHEN t1.MATNR REGEXP '^[0-9]+$' THEN TRIM(LEADING '0' FROM t1.MATNR) ELSE t1.MATNR END AS MATNR"),
                't1.MAKTX',
                't1.KWMENG',
                't1.QTY_GI',
                't1.QTY_BALANCE2',
                't1.KALAB',     // WHFG
                't1.KALAB2',    // FG
                't1.NETPR',
                't1.WAERK'
            )
            ->whereIn('t1.id', $ids)
            ->orderBy('t1.VBELN')->orderByRaw('CAST(t1.POSNR AS UNSIGNED)')
            ->get();

        if ($items->isEmpty()) {
            return back()->withErrors('Tidak ada item yang valid untuk diekspor.');
        }

        $locationMap  = ['2000' => 'Surabaya', '3000' => 'Semarang'];
        $locationName = $locationMap[$werks] ?? $werks;
        $auartDesc    = DB::table('maping')->where('IV_WERKS', $werks)->where('IV_AUART', $auart)->value('Deskription');

        if ($exportType === 'excel') {
            $fileName = "PO_Items_{$locationName}_{$auart}_" . date('Ymd_His') . ".xlsx";
            return Excel::download(new PoItemsExport($items), $fileName);
        }

        // PDF
        $fileName = "PO_Items_{$locationName}_{$auart}_" . date('Ymd_His') . ".pdf";
        $pdf = Pdf::loadView('po_report.po_pdf_template', [
            'items'            => $items,
            'locationName'     => $locationName,
            'auartDescription' => $auartDesc,
            'werks'            => $werks,
            'auart'            => $auart,
            'today'            => now(),
        ])
            ->setPaper('a4', 'landscape');

        return $pdf->stream($fileName);
    }
}
