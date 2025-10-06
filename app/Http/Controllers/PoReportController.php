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

            // A) Agregat SEMUA outstanding per customer
            $allAggSubquery = DB::table('so_yppr079_t1 as t1a')
                ->join('so_yppr079_t2 as t2a', 't2a.VBELN', '=', 't1a.VBELN')
                ->select(
                    't2a.KUNNR',
                    DB::raw('CAST(SUM(CAST(t1a.QTY_BALANCE2 AS DECIMAL(18,3))) AS DECIMAL(18,3)) AS TOTAL_OUTS_QTY'),
                    DB::raw('CAST(SUM(CAST(t1a.TOTPR        AS DECIMAL(18,2))) AS DECIMAL(18,2)) AS TOTAL_ALL_VALUE')
                )
                ->where('t1a.IV_WERKS_PARAM', $werks)
                ->where('t1a.IV_AUART_PARAM', $auart)
                ->whereRaw('CAST(t1a.QTY_BALANCE2 AS DECIMAL(18,3)) > 0')
                ->groupBy('t2a.KUNNR');

            // B) Agregat OVERDUE value per customer
            $overdueValueSubquery = DB::table('so_yppr079_t2 as t2_inner')
                ->join('so_yppr079_t1 as t1', function ($j) {
                    $j->on(DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2_inner.VBELN AS CHAR))'));
                })
                ->select(
                    't2_inner.KUNNR',
                    DB::raw('CAST(SUM(CAST(t1.TOTPR AS DECIMAL(18,2))) AS DECIMAL(18,2)) AS TOTAL_OVERDUE_VALUE')
                )
                ->where('t2_inner.IV_WERKS_PARAM', $werks)
                ->where('t2_inner.IV_AUART_PARAM', $auart)
                ->whereRaw('CAST(t1.QTY_BALANCE2 AS DECIMAL(18,3)) > 0')
                ->whereRaw("{$safeEdatuInner} < CURDATE()")
                ->groupBy('t2_inner.KUNNR');

            // Overview Customer (bisa paginate jika mau)
            $rows = DB::table('so_yppr079_t2 as t2')
                ->leftJoinSub($allAggSubquery, 'agg_all', fn($j) => $j->on('t2.KUNNR', '=', 'agg_all.KUNNR'))
                ->leftJoinSub($overdueValueSubquery, 'agg_overdue', fn($j) => $j->on('t2.KUNNR', '=', 'agg_overdue.KUNNR'))
                ->select(
                    't2.KUNNR',
                    DB::raw('MAX(t2.NAME1) AS NAME1'),
                    DB::raw('MAX(t2.WAERK) AS WAERK'),
                    DB::raw('COALESCE(MAX(agg_all.TOTAL_OUTS_QTY),0)      AS TOTAL_OUTS_QTY'),
                    DB::raw('COALESCE(MAX(agg_all.TOTAL_ALL_VALUE),0)     AS TOTAL_ALL_VALUE'),
                    DB::raw('COALESCE(MAX(agg_overdue.TOTAL_OVERDUE_VALUE),0) AS TOTAL_OVERDUE_VALUE'),
                    DB::raw("COUNT(DISTINCT CASE WHEN {$safeEdatu} < CURDATE() THEN t2.VBELN ELSE NULL END) AS SO_LATE_COUNT")
                )
                ->where('t2.IV_WERKS_PARAM', $werks)
                ->where('t2.IV_AUART_PARAM', $auart)
                ->whereExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('so_yppr079_t1 as t1_check')
                        ->whereColumn('t1_check.VBELN', 't2.VBELN')
                        ->where('t1_check.QTY_BALANCE2', '!=', 0);
                })
                ->whereNotNull('t2.NAME1')->where('t2.NAME1', '!=', '')
                ->groupBy('t2.KUNNR')
                ->orderBy('NAME1', 'asc')
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
