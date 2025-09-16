<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\SoItemsExport;

class SalesOrderController extends Controller
{
    /**
     * Halaman Outstanding SO (report by customer).
     */
    public function index(Request $request)
    {
        if ($request->filled('werks') && !$request->filled('auart')) {
            $mapping = DB::table('maping')
                ->select('IV_WERKS', 'IV_AUART', 'Deskription')
                ->where('IV_WERKS', $request->werks)
                ->orderBy('IV_AUART')
                ->get();
            $defaultType = $mapping->first(function ($item) {
                return str_contains(strtolower($item->Deskription), 'export');
            });
            if (!$defaultType) {
                $defaultType = $mapping->first();
            }
            if ($defaultType) {
                $params = array_merge($request->query(), ['auart' => $defaultType->IV_AUART]);
                return redirect()->route('so.index', $params);
            }
        }
        $werks = $request->query('werks');
        $auart = $request->query('auart');
        $mapping = DB::table('maping')
            ->select('IV_WERKS', 'IV_AUART', 'Deskription')
            ->orderBy('IV_WERKS')->orderBy('IV_AUART')
            ->get()
            ->groupBy('IV_WERKS')
            ->map(fn($g) => $g->unique('IV_AUART')->values());
        $rows = null;
        $selectedDescription = '';
        if ($werks && $auart) {
            $selectedMapping = DB::table('maping')
                ->where('IV_WERKS', $werks)
                ->where('IV_AUART', $auart)
                ->first();
            $selectedDescription = $selectedMapping->Deskription ?? '';

            // Definisikan cara penanganan tanggal yang aman (robust)
            $safeEdatu = "COALESCE(STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'), STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y'))";
            $safeEdatuInner = "COALESCE(STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2_inner.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'), STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2_inner.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y'))";

            // Buat subquery untuk menghitung total nilai HANYA dari SO yang telat per customer
            $overdueValueSubquery = DB::table('so_yppr079_t2 as t2_inner')
                ->join('so_yppr079_t1 as t1', 't1.VBELN', '=', 't2_inner.VBELN')
                ->select(
                    't2_inner.KUNNR',
                    DB::raw('SUM(t1.TOTPR) AS TOTAL_OVERDUE_VALUE')
                )
                ->where('t2_inner.IV_WERKS_PARAM', $werks)
                ->where('t2_inner.IV_AUART_PARAM', $auart)
                ->where('t1.PACKG', '!=', 0) // Filter spesifik untuk SO
                ->whereRaw("{$safeEdatuInner} < CURDATE()") // Kondisi utama: HANYA YANG TELAT
                ->groupBy('t2_inner.KUNNR');

            // Query utama yang menggabungkan data customer dengan hasil subquery di atas
            $rows = DB::table('so_yppr079_t2 as t2')
                // Menggunakan LEFT JOIN agar customer yang tidak punya SO telat tetap muncul (dengan value 0)
                ->leftJoinSub($overdueValueSubquery, 'overdue_values', function ($join) {
                    $join->on('t2.KUNNR', '=', 'overdue_values.KUNNR');
                })
                ->select(
                    't2.KUNNR',
                    DB::raw('MAX(t2.NAME1) AS NAME1'),
                    DB::raw('MAX(t2.WAERK) AS WAERK'),
                    // Ambil nilai dari subquery, jika tidak ada (NULL), anggap 0
                    DB::raw('COALESCE(MAX(overdue_values.TOTAL_OVERDUE_VALUE), 0) AS TOTAL_VALUE'),
                    // Kalkulasi COUNT dan PCT tetap sama
                    DB::raw("COUNT(DISTINCT CASE WHEN {$safeEdatu} < CURDATE() THEN t2.VBELN ELSE NULL END) as SO_LATE_COUNT"),
                    DB::raw("
                        ROUND(
                            (COUNT(DISTINCT CASE WHEN {$safeEdatu} < CURDATE() THEN t2.VBELN ELSE NULL END) / COUNT(DISTINCT t2.VBELN)) * 100, 2
                        ) as LATE_PCT
                    ")
                )
                ->where('t2.IV_WERKS_PARAM', $werks)
                ->where('t2.IV_AUART_PARAM', $auart)
                // Pastikan kita hanya memproses SO yang memiliki item siap kirim
                ->whereExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('so_yppr079_t1 as t1_check')
                        ->whereColumn('t1_check.VBELN', 't2.VBELN')
                        ->where('t1_check.PACKG', '!=', 0);
                })
                ->where(function ($query) {
                    $query->whereNotNull('t2.NAME1')->where('t2.NAME1', '!=', '');
                })
                ->groupBy('t2.KUNNR')
                ->orderBy('NAME1', 'asc')
                ->paginate(25)
                ->withQueryString();
        }
        return view('sales_order.so_report', [
            'mapping' => $mapping,
            'rows' => $rows,
            'selected' => ['werks' => $werks, 'auart' => $auart],
            'selectedDescription' => $selectedDescription,
        ]);
    }

    /**
     * API: Ambil daftar SO outstanding untuk 1 customer.
     */
    public function apiGetSoByCustomer(Request $request)
    {
        $request->validate(['kunnr' => 'required|string', 'werks' => 'required|string', 'auart' => 'required|string']);
        $rows = DB::table('so_yppr079_t1 as t1')
            ->leftJoin('so_yppr079_t2 as t2', 't1.VBELN', '=', 't2.VBELN')
            ->select(
                't1.VBELN',
                't2.EDATU',
                't1.WAERK',
                DB::raw('SUM(t1.TOTPR2) as total_value'),
                DB::raw('COUNT(DISTINCT t1.id) as item_count')
            )
            ->where('t1.KUNNR', $request->kunnr)
            ->where('t1.IV_WERKS_PARAM', $request->werks)
            ->where('t1.IV_AUART_PARAM', $request->auart)
            ->where('t1.PACKG', '!=', 0)
            ->groupBy('t1.VBELN', 't2.EDATU', 't1.WAERK')
            ->get();
        $today = now()->startOfDay();
        foreach ($rows as $row) {
            $overdue = 0;
            $formattedEdatu = '';
            if (!empty($row->EDATU) && $row->EDATU !== '0000-00-00') {
                try {
                    $edatuDate = Carbon::parse($row->EDATU);
                    $formattedEdatu = $edatuDate->format('d-m-Y');
                    $overdue = $today->diffInDays($edatuDate->startOfDay(), false);
                } catch (\Exception $e) { /* Biarkan kosong jika format tanggal tidak valid */
                }
            }
            $row->Overdue        = $overdue;
            $row->FormattedEdatu = $formattedEdatu;
        }

        // =========================================================================
        // ========= PERBAIKAN SORTING DI SINI: KEMBALIKAN KE sortBy() BIASA =========
        // =========================================================================
        return response()->json(['ok' => true, 'data' => $rows->sortBy('Overdue')->values()]);
    }

    /**
     * API: Ambil item untuk 1 SO, sekarang dengan data remark.
     */
    public function apiGetItemsBySo(Request $request)
    {
        $request->validate(['vbeln' => 'required|string', 'werks' => 'required|string', 'auart' => 'required|string']);

        $items = DB::table('so_yppr079_t1 as t1')
            ->leftJoin('item_remarks as ir', function ($j) {
                $j->on('ir.IV_WERKS_PARAM', '=', 't1.IV_WERKS_PARAM')
                    ->on('ir.IV_AUART_PARAM', '=', 't1.IV_AUART_PARAM')
                    ->on('ir.VBELN', '=', 't1.VBELN')
                    ->on('ir.POSNR', '=', 't1.POSNR');
            })
            ->select(
                't1.id', // boleh tetap dipakai untuk ceklis UI, tapi TIDAK untuk remark
                't1.MAKTX',
                't1.KWMENG',
                't1.PACKG',
                't1.KALAB2',
                't1.DAYX',
                't1.ASSYM',
                't1.PAINT',
                't1.MENGE',
                't1.NETPR',
                't1.TOTPR2',
                't1.TOTPR',
                't1.NETWR',
                't1.WAERK',
                DB::raw("TRIM(LEADING '0' FROM t1.POSNR) as POSNR"),
                DB::raw("CASE WHEN t1.MATNR REGEXP '^[0-9]+$' THEN TRIM(LEADING '0' FROM t1.MATNR) ELSE t1.MATNR END as MATNR"),
                // kunci natural untuk dikirim ke FE
                't1.IV_WERKS_PARAM as WERKS_KEY',
                't1.IV_AUART_PARAM as AUART_KEY',
                't1.VBELN as VBELN_KEY',
                't1.POSNR as POSNR_KEY',
                // remark
                'ir.remark'
            )
            ->where('t1.VBELN', $request->vbeln)
            ->where('t1.IV_WERKS_PARAM', $request->werks)
            ->where('t1.IV_AUART_PARAM', $request->auart)
            ->where('t1.PACKG', '!=', 0)
            ->orderByRaw('CAST(t1.POSNR AS UNSIGNED) asc')
            ->get();

        return response()->json(['ok' => true, 'data' => $items]);
    }

    /**
     * Method ini menangani ekspor PDF dan Excel.
     */
    public function exportData(Request $request)
    {
        $validated = $request->validate([
            'item_ids'    => 'required|array',
            'item_ids.*'  => 'integer',
            'export_type' => 'required|string|in:pdf,excel',
            'werks'       => 'required|string',
            'auart'       => 'required|string',
        ]);

        $itemIds = $validated['item_ids'];
        $exportType = $validated['export_type'];
        $werks = $validated['werks'];
        $auart = $validated['auart'];

        $items = DB::table('so_yppr079_t1 as t1')
            ->leftJoin('item_remarks as ir', function ($j) {
                $j->on('ir.IV_WERKS_PARAM', '=', 't1.IV_WERKS_PARAM')
                    ->on('ir.IV_AUART_PARAM', '=', 't1.IV_AUART_PARAM')
                    ->on('ir.VBELN', '=', 't1.VBELN')
                    ->on('ir.POSNR', '=', 't1.POSNR');
            })
            ->whereIn('t1.id', $itemIds) // <- kalau seleksi masih pakai id, ini tetap boleh
            ->select(/* kolom2, lalu */'ir.remark')
            ->orderBy('t1.VBELN')->orderByRaw('CAST(t1.POSNR AS UNSIGNED) asc')
            ->get();

        foreach ($items as $item) {
            $item->MATNR = $item->MATNR_formatted;
        }

        $locationMap = ['2000' => 'Surabaya', '3000' => 'Semarang'];
        $locationName = $locationMap[$werks] ?? $werks;
        $auartDescription = DB::table('maping')->where('IV_WERKS', $werks)->where('IV_AUART', $auart)->value('Deskription');

        $vbelns = $items->pluck('VBELN')->unique();
        $headers = DB::table('so_yppr079_t2')->whereIn('VBELN', $vbelns)->select('VBELN', 'BSTNK')->get()->keyBy('VBELN');

        foreach ($items as $item) {
            $item->headerInfo = $headers->get($item->VBELN);
        }

        $fileExtension = $exportType === 'excel' ? 'xlsx' : 'pdf';
        $fileName = "Outstanding_SO_{$locationName}_{$auart}_" . date('Ymd_His') . ".{$fileExtension}";

        if ($exportType === 'excel') {
            return Excel::download(new SoItemsExport($items), $fileName);
        }

        if ($exportType === 'pdf') {
            $dataForPdf = [
                'items' => $items,
                'locationName' => $locationName,
                'werks' => $werks,
                'auartDescription' => $auartDescription,
                'auart' => $auart,
            ];
            $pdf = Pdf::loadView('sales_order.so_pdf_template', $dataForPdf)
                ->setPaper('a4', 'landscape');

            return $pdf->stream($fileName);
        }
    }

    /**
     * API untuk menyimpan catatan (remark) pada sebuah item.
     */
    public function apiSaveRemark(Request $request)
    {
        $validated = $request->validate([
            'werks' => 'required|string',
            'auart' => 'required|string',
            'vbeln' => 'required|string',
            'posnr' => 'required|string',
            'remark' => 'nullable|string|max:1000',
        ]);

        $remarkText = trim($validated['remark'] ?? '');

        try {
            if ($remarkText === '') {
                DB::table('item_remarks')->where([
                    'IV_WERKS_PARAM' => $validated['werks'],
                    'IV_AUART_PARAM' => $validated['auart'],
                    'VBELN'          => $validated['vbeln'],
                    'POSNR'          => $validated['posnr'],
                ])->delete();
            } else {
                DB::table('item_remarks')->updateOrInsert(
                    [
                        'IV_WERKS_PARAM' => $validated['werks'],
                        'IV_AUART_PARAM' => $validated['auart'],
                        'VBELN'          => $validated['vbeln'],
                        'POSNR'          => $validated['posnr'],
                    ],
                    [
                        'remark'     => $remarkText,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
            return response()->json(['ok' => true, 'message' => 'Catatan berhasil diproses.']);
        } catch (\Exception $e) {
            return response()->json(['ok' => false, 'message' => 'Gagal memproses catatan ke database.'], 500);
        }
    }
}
