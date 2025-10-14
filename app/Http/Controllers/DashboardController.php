<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Str;
use Barryvdh\DomPDF\Facade\Pdf;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        // 1) Dekripsi q (jika ada) lalu merge ke $request
        $decryptedParams = [];
        if ($request->has('q')) {
            try {
                $decryptedParams = Crypt::decrypt($request->query('q'));
                if (!is_array($decryptedParams)) {
                    $decryptedParams = [];
                }
            } catch (DecryptException $e) {
                return redirect()->route('dashboard')->withErrors('Link tidak valid atau telah kadaluwarsa.');
            }
        }
        if (!empty($decryptedParams)) {
            $request->merge($decryptedParams);
        }

        // 2) Filter khusus PO dashboard
        $location      = $request->query('location');      // '2000' | '3000' | null
        $type          = $request->query('type');          // 'lokal' | 'export' | null

        // 3) Mapping untuk sidebar/label
        $rawMapping = DB::table('maping')
            ->select('IV_WERKS', 'IV_AUART', 'Deskription')
            ->orderBy('IV_WERKS')->orderBy('IV_AUART')
            ->get();

        // MODIFIKASI: Filter 'Replace' dari mapping sebelum grouping (untuk dropdown)
        $filteredMapping = $rawMapping->reject(function ($item) {
            return Str::contains(strtolower($item->Deskription), 'replace');
        });

        $mapping = $filteredMapping
            ->groupBy('IV_WERKS')
            ->map(fn($g) => $g->unique('IV_AUART')->values());

        // 4) Variabel dashboard
        $chartData = [];
        $selectedLocationName = 'All Locations';
        $selectedTypeName = 'All Types';

        // ===== PO DASHBOARD =====
        $today = now()->startOfDay();
        if ($location === '2000') $selectedLocationName = 'Surabaya';
        if ($location === '3000') $selectedLocationName = 'Semarang';

        // Parser EDATU (format campuran)
        $safeEdatu = "
            COALESCE(
                STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y'),
                STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d')
            )";

        // Basis filter sama seperti report (type & lokasi): berbasis T2
        $baseQuery = DB::table('so_yppr079_t2 as t2');

        // =========================================================================
        // LOGIKA PENGGABUNGAN DATA (EXPORT + REPLACE)
        // =========================================================================

        // Dapatkan semua AUART 'Replace' dari DB
        $replaceAuartCodes = $rawMapping->filter(function ($item) {
            return Str::contains(strtolower($item->Deskription), 'replace');
        })->pluck('IV_AUART')->unique()->toArray();

        // Dapatkan semua AUART 'Export' (non-Local, non-Replace)
        $exportAuartCodes = $rawMapping->filter(function ($item) {
            $d = strtolower($item->Deskription);
            return Str::contains($d, 'export')
                && !Str::contains($d, 'local')
                && !Str::contains($d, 'replace');
        })->pluck('IV_AUART')->unique()->toArray();

        if ($type === 'lokal') {
            $selectedTypeName = 'Lokal';
            // Logic LOKAL tidak berubah: tetap hanya mengambil AUART Lokal
            $baseQuery->whereIn(DB::raw('(t2.IV_AUART_PARAM, t2.IV_WERKS_PARAM)'), function ($query) {
                $query->select('IV_AUART', 'IV_WERKS')->from('maping')->where('Deskription', 'like', '%Local%')
                    ->union(
                        DB::table('so_yppr079_t2')
                            ->select('IV_AUART_PARAM as IV_AUART', 'IV_WERKS_PARAM as IV_WERKS')
                            ->groupBy('IV_AUART_PARAM', 'IV_WERKS_PARAM')
                            ->havingRaw("SUM(CASE WHEN WAERK = 'IDR' THEN 1 ELSE 0 END) > 0 AND SUM(CASE WHEN WAERK = 'USD' THEN 1 ELSE 0 END) = 0")
                    );
            });
        } elseif ($type === 'export') {
            $selectedTypeName = 'Export';
            // Logic EXPORT: gabungkan AUART Export asli dan AUART Replace
            $auartToQuery = array_merge($exportAuartCodes, $replaceAuartCodes);

            $baseQuery->whereIn('t2.IV_AUART_PARAM', $auartToQuery);
        }
        $baseQuery->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc));

        // =========================================================================
        // Perhitungan KPI Awal (Outstanding/Overdue Value & Qty)
        // =========================================================================

        // 1. Query untuk JUMLAH PO/SO (QTY PO Count) - Mencakup semua baris T2
        $kpiQtyQuery = (clone $baseQuery)
            ->selectRaw("
                t2.IV_WERKS_PARAM as werks,
                t2.WAERK as currency,
                COUNT(DISTINCT t2.VBELN) as total_qty, 
                COUNT(DISTINCT CASE WHEN {$safeEdatu} < CURDATE() THEN t2.VBELN ELSE NULL END) as overdue_qty
            ")
            ->groupBy('t2.IV_WERKS_PARAM', 't2.WAERK')
            ->get();

        // 2. Query untuk NILAI (Outstanding Value) - HANYA PO yang memiliki Outstanding Qty
        $kpiValueQuery = (clone $baseQuery)
            ->leftJoin(
                'so_yppr079_t1 as t1',
                DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                '=',
                DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
            )
            ->selectRaw("
                t2.IV_WERKS_PARAM as werks,
                t2.WAERK as currency,
                CAST(SUM(t1.TOTPR) AS DECIMAL(18,2)) as total_value,
                CAST(SUM(CASE WHEN {$safeEdatu} < CURDATE() THEN t1.TOTPR ELSE 0 END) AS DECIMAL(18,2)) as overdue_value
            ")
            ->whereRaw('CAST(t1.QTY_BALANCE2 AS DECIMAL(18,3)) > 0') // Filter Item Outstanding
            ->groupBy('t2.IV_WERKS_PARAM', 't2.WAERK')
            ->get();


        // Inisialisasi data KPI
        $kpi = [
            'smg_usd_val' => 0,
            'smg_usd_qty' => 0,
            'smg_usd_overdue_val' => 0,
            'smg_usd_overdue_qty' => 0,
            'smg_idr_val' => 0,
            'smg_idr_qty' => 0,
            'smg_idr_overdue_val' => 0,
            'smg_idr_overdue_qty' => 0,
            'sby_usd_val' => 0,
            'sby_usd_qty' => 0,
            'sby_usd_overdue_val' => 0,
            'sby_usd_overdue_qty' => 0,
            'sby_idr_val' => 0,
            'sby_idr_qty' => 0,
            'sby_idr_overdue_val' => 0,
            'sby_idr_overdue_qty' => 0,
        ];

        // Isi data KPI NILAI (Outstanding Value & Overdue Value)
        foreach ($kpiValueQuery as $row) {
            $loc_prefix = $row->werks === '3000' ? 'smg' : 'sby';
            $cur_suffix = strtolower($row->currency);

            $kpi["{$loc_prefix}_{$cur_suffix}_val"] = (float) $row->total_value;
            $kpi["{$loc_prefix}_{$cur_suffix}_overdue_val"] = (float) $row->overdue_value;
        }

        // Isi data KPI JUMLAH (Qty PO Count & Overdue Qty Count)
        foreach ($kpiQtyQuery as $row) {
            $loc_prefix = $row->werks === '3000' ? 'smg' : 'sby';
            $cur_suffix = strtolower($row->currency);

            // Total Outstanding Qty (Menggunakan total PO/SO dari T2 tanpa filter QTY_BALANCE2)
            $kpi["{$loc_prefix}_{$cur_suffix}_qty"] = (int) $row->total_qty;
            // Overdue Qty (Menggunakan total PO/SO dari T2 yang overdue)
            $kpi["{$loc_prefix}_{$cur_suffix}_overdue_qty"] = (int) $row->overdue_qty;
        }

        $chartData['kpi_new'] = $kpi;

        // KPI lama (diperlukan agar script JS lama/lama yang dipertahankan tidak error)
        $chartData['kpi'] = [
            'total_outstanding_value_usd' => $kpi['smg_usd_val'] + $kpi['sby_usd_val'],
            'total_outstanding_value_idr' => $kpi['smg_idr_val'] + $kpi['sby_idr_val'],
            'total_outstanding_so' => $kpi['smg_usd_qty'] + $kpi['smg_idr_qty'] + $kpi['sby_usd_qty'] + $kpi['sby_idr_qty'],
            'total_overdue_so'      => $kpi['smg_usd_overdue_qty'] + $kpi['smg_idr_overdue_qty'] + $kpi['sby_usd_overdue_qty'] + $kpi['sby_idr_overdue_qty'],
        ];
        $chartData['kpi']['overdue_rate'] =
            $chartData['kpi']['total_outstanding_so'] > 0
            ? ($chartData['kpi']['total_overdue_so'] / $chartData['kpi']['total_outstanding_so']) * 100
            : 0;

        // Outstanding by Location (DIKOSONGKAN)
        $chartData['outstanding_by_location'] = [];

        // Status ring (DIKOSONGKAN)
        $chartData['so_status'] = [
            'overdue'       => 0,
            'due_this_week' => 0,
            'on_time'       => 0,
        ];

        // =========================================================================
        // MODIFIKASI: QUERY GRAFIK YANG DIBAGI PER LOKASI
        // =========================================================================

        $locationQueries = [
            'smg' => (clone $baseQuery)->where('t2.IV_WERKS_PARAM', '3000'),
            'sby' => (clone $baseQuery)->where('t2.IV_WERKS_PARAM', '2000'),
        ];

        // 1. Top customers (USD) - PER LOKASI
        foreach ($locationQueries as $prefix => $query) {
            $chartData["top_customers_value_usd_{$prefix}"] = (clone $query)
                ->join(
                    'so_yppr079_t1 as t1',
                    DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                    '=',
                    DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
                )
                ->select('t2.NAME1', DB::raw('SUM(t1.TOTPR) as total_value'), DB::raw('COUNT(DISTINCT t2.VBELN) as so_count'))
                ->where('t2.WAERK', 'USD')
                ->whereRaw('CAST(t1.QTY_BALANCE2 AS DECIMAL(18,3)) > 0') // Hanya outstanding item
                ->groupBy('t2.NAME1')
                ->having('total_value', '>', 0)
                ->orderByDesc('total_value')
                ->limit(4)
                ->get();
        }

        // 2. Top customers (IDR) - PER LOKASI
        foreach ($locationQueries as $prefix => $query) {
            $chartData["top_customers_value_idr_{$prefix}"] = (clone $query)
                ->join(
                    'so_yppr079_t1 as t1',
                    DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                    '=',
                    DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
                )
                ->select('t2.NAME1', DB::raw('SUM(t1.TOTPR) as total_value'), DB::raw('COUNT(DISTINCT t2.VBELN) as so_count'))
                ->where('t2.WAERK', 'IDR')
                ->whereRaw('CAST(t1.QTY_BALANCE2 AS DECIMAL(18,3)) > 0') // Hanya outstanding item
                ->groupBy('t2.NAME1')
                ->having('total_value', '>', 0)
                ->orderByDesc('total_value')
                ->limit(4)
                ->get();
        }

        // 3. Top customers overdue - PER LOKASI (Count PO yang overdue)
        foreach ($locationQueries as $prefix => $query) {
            $chartData["top_customers_overdue_{$prefix}"] = (clone $query)
                ->select(
                    't2.NAME1',
                    DB::raw('COUNT(DISTINCT t2.VBELN) as overdue_count'),
                    // FIX: Gunakan MAX() pada kolom non-agregat untuk memuaskan ONLY_FULL_GROUP_BY
                    DB::raw("MAX(TRIM(t2.IV_WERKS_PARAM)) as locations"),
                    DB::raw("COUNT(DISTINCT CASE WHEN t2.IV_WERKS_PARAM = '3000' THEN t2.VBELN ELSE NULL END) as smg_count"),
                    DB::raw("COUNT(DISTINCT CASE WHEN t2.IV_WERKS_PARAM = '2000' THEN t2.VBELN ELSE NULL END) as sby_count")
                )
                ->whereRaw("{$safeEdatu} < CURDATE()") // Filter: PO/SO overdue
                ->groupBy('t2.NAME1')
                ->having('overdue_count', '>', 0)
                ->orderByDesc('overdue_count')
                ->limit(4)
                ->get();
        }


        // Performance analysis - AKTIFKAN KEMBALI
        $performanceQueryBase = DB::table('maping as m')
            ->join('so_yppr079_t2 as t2', function ($join) {
                $join->on('m.IV_WERKS', '=', 't2.IV_WERKS_PARAM')
                    ->on('m.IV_AUART', '=', 't2.IV_AUART_PARAM');
            })
            ->leftJoin(
                'so_yppr079_t1 as t1',
                DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                '=',
                DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
            )
            ->whereRaw('CAST(t1.QTY_BALANCE2 AS DECIMAL(18,3)) > 0'); // Hanya hitung item yang outstanding

        $typesToFilter = null;
        if ($type === 'lokal' || $type === 'export') {
            $cloneForFilter = (clone $baseQuery)->select('t2.IV_AUART_PARAM', 't2.IV_WERKS_PARAM')->distinct();
            $typesToFilter = $cloneForFilter->get()
                ->map(fn($item) => $item->IV_AUART_PARAM . '-' . $item->IV_WERKS_PARAM)
                ->toArray();
        }
        if ($typesToFilter !== null) {
            $performanceQueryBase->whereIn(DB::raw("CONCAT(m.IV_AUART, '-', m.IV_WERKS)"), $typesToFilter);
        }

        $safeEdatuPerf = $safeEdatu;
        $performanceQuery = $performanceQueryBase->select(
            'm.Deskription',
            'm.IV_WERKS',
            'm.IV_AUART',
            DB::raw('COUNT(DISTINCT t2.VBELN) as total_so'),
            DB::raw("SUM(CASE WHEN t2.WAERK = 'IDR' AND {$safeEdatuPerf} < CURDATE() THEN CAST(t1.TOTPR AS DECIMAL(18,2)) ELSE 0 END) as total_value_idr"),
            DB::raw("SUM(CASE WHEN t2.WAERK = 'USD' AND {$safeEdatuPerf} < CURDATE() THEN CAST(t1.TOTPR AS DECIMAL(18,2)) ELSE 0 END) as total_value_usd"),
            DB::raw("COUNT(DISTINCT CASE WHEN {$safeEdatuPerf} < CURDATE() THEN t2.VBELN ELSE NULL END) as overdue_so_count"),
            DB::raw("COUNT(DISTINCT CASE WHEN DATEDIFF(CURDATE(), {$safeEdatuPerf}) BETWEEN 1 AND 30 THEN t2.VBELN ELSE NULL END) as overdue_1_30"),
            DB::raw("COUNT(DISTINCT CASE WHEN DATEDIFF(CURDATE(), {$safeEdatuPerf}) BETWEEN 31 AND 60 THEN t2.VBELN ELSE NULL END) as overdue_31_60"),
            DB::raw("COUNT(DISTINCT CASE WHEN DATEDIFF(CURDATE(), {$safeEdatuPerf}) BETWEEN 61 AND 90 THEN t2.VBELN ELSE NULL END) as overdue_61_90"),
            DB::raw("COUNT(DISTINCT CASE WHEN DATEDIFF(CURDATE(), {$safeEdatuPerf}) > 90 THEN t2.VBELN ELSE NULL END) as overdue_over_90")
        )
            ->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc))
            // FIX: Tambahkan kolom m.IV_WERKS, m.IV_AUART, m.Deskription ke GROUP BY 
            ->groupBy('m.IV_WERKS', 'm.IV_AUART', 'm.Deskription')
            ->orderBy('m.IV_WERKS')->orderBy('m.Deskription')
            ->get();

        $chartData['so_performance_analysis'] = $performanceQuery;

        // Small qty by customer - TETAP
        $smallQtyByCustomerQuery = (clone $baseQuery)
            ->join(
                'so_yppr079_t1 as t1',
                DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                '=',
                DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
            )
            ->select('t2.NAME1', 't2.IV_WERKS_PARAM', DB::raw('COUNT(t1.POSNR) as item_count'))
            ->where('t1.QTY_BALANCE2', '>', 0)
            ->where('t1.QTY_BALANCE2', '<=', 5)
            ->whereRaw('CAST(t1.QTY_GI AS DECIMAL(18,3)) > 0') // Filter QTY_GI > 0
            ->groupBy('t2.NAME1', 't2.IV_WERKS_PARAM')
            ->orderBy('t2.NAME1')
            ->get();

        $chartData['small_qty_by_customer'] = $smallQtyByCustomerQuery;


        // 5) Return view PO dashboard
        return view('dashboard', [
            'mapping'                   => $mapping,
            'chartData'                 => $chartData,
            'selectedLocation'          => $location,
            'selectedLocationName'      => $selectedLocationName,
            'selectedType'              => $type,
            'selectedTypeName'          => $selectedTypeName,
            'view'                      => 'po', // fix ke 'po'
        ]);
    }

    public function apiPoOverdueDetails(Request $request)
    {
        $request->validate([
            'werks'     => 'required|string',
            'auart'     => 'required|string',
            'bucket'    => 'required|string|in:on_track,1_30,31_60,61_90,gt_90',
            'kunnr'     => 'nullable|string', // Customer Number opsional
        ]);

        $werks      = $request->query('werks');
        $auart      = $request->query('auart');
        $bucket     = $request->query('bucket');
        $kunnr      = $request->query('kunnr'); // AMBIL KUNNR

        // =========================================================================
        // Logika Penggabungan Data Export dan Replace (Harus sama di semua Controller)
        $mapping = DB::table('maping')->get();
        $exportAuartCodes = $mapping->filter(function ($item) {
            $d = strtolower($item->Deskription);
            return Str::contains($d, 'export')
                && !Str::contains($d, 'local')
                && !Str::contains($d, 'replace');
        })->pluck('IV_AUART')->unique()->toArray();
        $replaceAuartCodes = $mapping->filter(function ($item) {
            return Str::contains(strtolower($item->Deskription), 'replace');
        })->pluck('IV_AUART')->unique()->toArray();

        $auartList = [$auart];
        if (in_array($auart, $exportAuartCodes) && !in_array($auart, $replaceAuartCodes)) {
            $auartList = array_merge($exportAuartCodes, $replaceAuartCodes);
        }
        $auartList = array_unique($auartList);
        // =========================================================================

        // Parser tanggal aman
        $safeEdatu = "COALESCE(
    STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
    STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
    )";

        $q = DB::table('so_yppr079_t2 as t2')
            ->selectRaw("
        TRIM(t2.BSTNK)      AS PO,
        TRIM(t2.VBELN)      AS SO,
        DATE_FORMAT({$safeEdatu}, '%d-%m-%Y')   AS EDATU,   
        DATEDIFF(CURDATE(), {$safeEdatu})   AS OVERDUE_DAYS,
        MAX(t2.NAME1)       AS CUSTOMER_NAME_MODAL       -- <<< PENAMBAHAN KOLOM
        ")
            ->where('t2.IV_WERKS_PARAM', $werks)
            ->whereIn('t2.IV_AUART_PARAM', $auartList)
            ->when($kunnr, fn($q) => $q->where('t2.KUNNR', $kunnr));

        // FILTER UTAMA: Hanya PO/SO yang memiliki item Outstanding (QTY_BALANCE2 > 0)
        $q->whereExists(function ($qExist) use ($auartList, $werks) {
            $qExist->select(DB::raw(1))
                ->from('so_yppr079_t1 as t1_check')
                ->whereColumn('t1_check.VBELN', 't2.VBELN')
                ->whereIn('t1_check.IV_AUART_PARAM', $auartList)
                ->where('t1_check.IV_WERKS_PARAM', $werks)
                ->whereRaw('CAST(t1_check.QTY_BALANCE2 AS DECIMAL(18,3)) > 0');
        });

        switch ($bucket) {
            case 'on_track':
                $q->whereRaw("{$safeEdatu} >= CURDATE()");
                break;
            case '1_30':
                $q->whereRaw("DATEDIFF(CURDATE(), {$safeEdatu}) BETWEEN 1 AND 30");
                break;
            case '31_60':
                $q->whereRaw("DATEDIFF(CURDATE(), {$safeEdatu}) BETWEEN 31 AND 60");
                break;
            case '61_90':
                $q->whereRaw("DATEDIFF(CURDATE(), {$safeEdatu}) BETWEEN 61 AND 90");
                break;
            case 'gt_90':
                $q->whereRaw("DATEDIFF(CURDATE(), {$safeEdatu}) > 90");
                break;
        }

        $rows = $q
            // Group by harus ditambahkan karena ada agregasi MAX(t2.NAME1)
            ->groupBy('t2.BSTNK', 't2.VBELN', 't2.EDATU')
            ->orderByRaw("CASE WHEN {$safeEdatu} >= CURDATE() THEN 0 ELSE 1 END")
            ->orderByRaw("CASE WHEN {$safeEdatu} >= CURDATE() THEN {$safeEdatu} ELSE NULL END ASC") // On-Track (EDATU ASC)
            ->orderByDesc('OVERDUE_DAYS') // Overdue (Days DESC)
            ->orderBy('t2.VBELN')
            ->get();

        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function apiT2(Request $req)
    {
        $kunnr = (string) $req->query('kunnr');
        $werks = $req->query('werks');
        $auart = $req->query('auart');

        if (!$kunnr) {
            return response()->json(['ok' => false, 'error' => 'kunnr missing'], 400);
        }

        // =========================================================================
        // MODIFIKASI: Logika Penggabungan Data Export dan Replace (apiT2) - Fix untuk Table 2
        // =========================================================================
        $mapping = DB::table('maping')->get();
        // Dapatkan AUART Export (non-replace)
        $exportAuartCodes = $mapping->filter(function ($item) {
            $d = strtolower($item->Deskription);
            return Str::contains($d, 'export')
                && !Str::contains($d, 'local')
                && !Str::contains($d, 'replace');
        })->pluck('IV_AUART')->unique()->toArray();
        // Dapatkan AUART Replace
        $replaceAuartCodes = $mapping->filter(function ($item) {
            return Str::contains(strtolower($item->Deskription), 'replace');
        })->pluck('IV_AUART')->unique()->toArray();

        $auartList = [empty($auart) ? 'NO_AUART' : $auart];

        // Jika AUART yang diklik adalah kode AUART EXPORT yang asli, kita gabungkan REPLACE.
        if (!empty($auart) && in_array($auart, $exportAuartCodes) && !in_array($auart, $replaceAuartCodes)) {
            $auartList = array_merge($exportAuartCodes, $replaceAuartCodes);
        }

        $auartList = array_unique(array_filter($auartList));
        // Konversi list menjadi string yang dikutip untuk digunakan dalam klausa IN (SQL)
        $auartListString = collect($auartList)->map(fn($a) => DB::getPdo()->quote($a))->join(',');

        // Klausa WHERE IN untuk subqueries T1
        $auartWhereInClause = empty($auartListString) ? "" : " AND t1.IV_AUART_PARAM IN ({$auartListString})";
        $auartWhereInClauseIr = empty($auartListString) ? "" : " AND ir.IV_AUART_PARAM IN ({$auartListString})";
        $werksClauseIr = strlen((string)$werks) ? " AND ir.IV_WERKS_PARAM = " . DB::getPdo()->quote($werks) : "";


        $q = DB::table('so_yppr079_t2 as t2')
            ->distinct()
            ->select([
                't2.VBELN',
                't2.BSTNK',
                't2.WAERK',
                't2.EDATU',

                // total value all
                DB::raw("(SELECT COALESCE(SUM(CAST(t1.TOTPR AS DECIMAL(18,2))),0)
                    FROM so_yppr079_t1 AS t1
                    WHERE TRIM(CAST(t1.VBELN AS CHAR)) = TRIM(CAST(t2.VBELN AS CHAR))
                    " . (strlen((string)$werks) ? " AND t1.IV_WERKS_PARAM = " . DB::getPdo()->quote($werks) : "") . "
                    {$auartWhereInClause}
                   ) AS total_value"),

                // outs qty
                DB::raw("(SELECT COALESCE(SUM(CAST(t1.QTY_BALANCE2 AS DECIMAL(18,3))),0)
                    FROM so_yppr079_t1 AS t1
                    WHERE TRIM(CAST(t1.VBELN AS CHAR)) = TRIM(CAST(t2.VBELN AS CHAR))
                    " . (strlen((string)$werks) ? " AND t1.IV_WERKS_PARAM = " . DB::getPdo()->quote($werks) : "") . "
                    {$auartWhereInClause}
                    AND CAST(t1.QTY_BALANCE2 AS DECIMAL(18,3)) > 0
                   ) AS outs_qty"),

                // >>> NEW: jumlah remark pada item untuk VBELN ini
                DB::raw("(SELECT COUNT(*)
                    FROM item_remarks_po AS ir
                    WHERE TRIM(CAST(ir.VBELN AS CHAR)) = TRIM(CAST(t2.VBELN AS CHAR))
                    {$werksClauseIr}
                    {$auartWhereInClauseIr}
                    AND TRIM(COALESCE(ir.remark,'')) <> ''
                   ) AS po_remark_count"),
            ])
            // Filter utama berdasarkan KUNNR
            ->where(function ($q) use ($kunnr) {
                $q->where('t2.KUNNR', $kunnr)
                    ->orWhereRaw('TRIM(CAST(t2.KUNNR AS CHAR)) = TRIM(?)', [$kunnr])
                    ->orWhereRaw('CAST(TRIM(t2.KUNNR) AS UNSIGNED) = CAST(TRIM(?) AS UNSIGNED)', [$kunnr]);
            })
            // Filter Plant (Werks)
            ->when(strlen((string)$werks) > 0, function ($q) use ($werks) {
                $q->where(function ($qq) use ($werks) {
                    $qq->where('t2.WERKS', $werks)
                        ->orWhere('t2.IV_WERKS_PARAM', $werks)
                        ->orWhereRaw('TRIM(CAST(t2.WERKS AS CHAR)) = TRIM(?)', [$werks])
                        ->orWhereRaw('TRIM(CAST(t2.IV_WERKS_PARAM AS CHAR)) = TRIM(?)', [$werks]);
                });
            })
            // Filter utama T2 menggunakan AUART LIST (Export + Replace)
            ->whereIn('t2.IV_AUART_PARAM', $auartList)
            ->get();

        $rows = $q;
        $today = new \DateTime();
        $today->setTime(0, 0, 0);

        foreach ($rows as $row) {
            $overdue = 0;
            $row->FormattedEdatu = '';
            if (!empty($row->EDATU) && $row->EDATU !== '0000-00-00') {
                try {
                    // Coba parse dengan 2 format
                    $edatuDate = \DateTime::createFromFormat('Y-m-d', $row->EDATU)
                        ?: \DateTime::createFromFormat('d-m-Y', $row->EDATU);

                    if ($edatuDate) {
                        $row->FormattedEdatu = $edatuDate->format('d-m-Y');
                        $edatuDate->setTime(0, 0, 0);

                        $diff = $today->diff($edatuDate);
                        // Hitung Overdue (positif = sudah lewat)
                        $overdue = $diff->invert ? (int)$diff->days : -(int)$diff->days;
                    }
                } catch (\Exception $e) {
                    // Jika gagal parse tanggal
                    $overdue = 0;
                }
            }
            $row->Overdue = $overdue; // Set nilai Overdue ke objek baris
        }

        $sortedRows = $rows->sortBy('Overdue')->values();
        return response()->json(['ok' => true, 'data' => $sortedRows]);
    }

    public function apiT3(Request $req)
    {
        $vbeln = trim((string) $req->query('vbeln'));
        if ($vbeln === '') {
            return response()->json(['ok' => false, 'error' => 'vbeln missing'], 400);
        }

        $werks = $req->query('werks');
        $auart = $req->query('auart');

        // === Tambahan: gabungkan Export + Replace bila perlu ===
        $mapping = DB::table('maping')->get();
        $exportAuartCodes = $mapping->filter(function ($item) {
            $d = strtolower($item->Deskription);
            return Str::contains($d, 'export') && !Str::contains($d, 'local') && !Str::contains($d, 'replace');
        })->pluck('IV_AUART')->unique()->toArray();

        $replaceAuartCodes = $mapping->filter(function ($item) {
            return Str::contains(strtolower($item->Deskription), 'replace');
        })->pluck('IV_AUART')->unique()->toArray();

        $auartList = [$auart];
        if ($auart && in_array($auart, $exportAuartCodes) && !in_array($auart, $replaceAuartCodes)) {
            $auartList = array_unique(array_merge($exportAuartCodes, $replaceAuartCodes));
        }

        $rows = DB::table('so_yppr079_t1 as t1')
            ->leftJoin(
                'so_yppr079_t2 as t2',
                DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'),
                '=',
                DB::raw('TRIM(CAST(t1.VBELN AS CHAR))')
            )
            // <<< PENAMBAHAN: LEFT JOIN ke tabel REMARK PO >>>
            ->leftJoin('item_remarks_po as ir', function ($j) {
                $j->on('ir.IV_WERKS_PARAM', '=', 't1.IV_WERKS_PARAM')
                    ->on('ir.IV_AUART_PARAM', '=', 't1.IV_AUART_PARAM')
                    ->on('ir.VBELN', '=', 't1.VBELN')
                    ->on('ir.POSNR', '=', 't1.POSNR');
            })
            // <<< AKHIR PENAMBAHAN JOINT REMARK >>>
            ->select(
                't1.id',
                DB::raw('TRIM(CAST(t1.VBELN AS CHAR)) as VBELN'),
                DB::raw("TRIM(LEADING '0' FROM t1.POSNR) as POSNR"),
                DB::raw("CASE WHEN t1.MATNR REGEXP '^[0-9]+$' THEN TRIM(LEADING '0' FROM t1.MATNR) ELSE t1.MATNR END as MATNR"),
                't1.MAKTX',
                't1.KWMENG',
                't1.QTY_GI',
                't1.QTY_BALANCE2',
                't1.KALAB',
                't1.KALAB2',
                't1.NETPR',
                't1.WAERK',
                // <<< KOLOM TAMBAHAN UNTUK REMARK LOGIC >>>
                't1.IV_WERKS_PARAM as WERKS_KEY',
                't1.IV_AUART_PARAM as AUART_KEY',
                DB::raw("LPAD(TRIM(t1.POSNR), 6, '0') as POSNR_DB"), // POSNR versi DB ('000010')
                'ir.remark' // Ambil remark
                // <<< AKHIR KOLOM TAMBAHAN >>>
            )
            ->whereRaw('TRIM(CAST(t1.VBELN AS CHAR)) = TRIM(?)', [$vbeln])
            ->when($werks, fn($q) => $q->where('t1.IV_WERKS_PARAM', $werks))
            ->when(!empty($auartList), fn($q) => $q->whereIn('t1.IV_AUART_PARAM', $auartList))
            ->orderByRaw('CAST(t1.POSNR AS UNSIGNED)')
            ->get();

        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function search(Request $request)
    {
        // 1) Validasi input
        $request->validate([
            'term' => 'required|string|max:100',
        ]);

        $term = trim((string) $request->input('term'));

        // 2) Cari di T2 (SO/PO) – cocokkan ke VBELN atau BSTNK
        $soInfo = DB::table('so_yppr079_t2')
            ->where(function ($q) use ($term) {
                // TRIM/CAST agar aman terhadap leading zeros dan tipe kolom
                $q->whereRaw('TRIM(CAST(VBELN AS CHAR)) = ?', [$term])
                    ->orWhereRaw('TRIM(CAST(BSTNK AS CHAR)) = ?', [$term]);
            })
            ->select('IV_WERKS_PARAM', 'IV_AUART_PARAM', 'KUNNR', 'VBELN', 'BSTNK')
            ->first();

        if (!$soInfo) {
            return back()
                ->withErrors(['term' => 'Nomor PO/SO "' . e($term) . '" tidak ditemukan.'])
                ->withInput();
        }

        $werks = trim((string) $soInfo->IV_WERKS_PARAM);   // '2000' | '3000'
        $auart = trim((string) $soInfo->IV_AUART_PARAM);   // contoh: 'ZRP1', 'ZRP2', 'KMI1', dst.

        // 3) Jika AUART adalah ZRP*, ganti menjadi AUART Export untuk plant tsb
        //    (Export = deskripsi mengandung "export" dan TIDAK mengandung "local"/"replace")
        $auartForReport = $auart;
        if (Str::startsWith($auart, 'ZRP')) {
            $exportAuart = DB::table('maping')
                ->where('IV_WERKS', $werks)
                ->whereRaw("LOWER(Deskription) LIKE '%export%'")
                ->whereRaw("LOWER(Deskription) NOT LIKE '%local%'")
                ->whereRaw("LOWER(Deskription) NOT LIKE '%replace%'")
                ->value('IV_AUART'); // ambil satu (yang utama) untuk plant tersebut

            if (!empty($exportAuart)) {
                $auartForReport = trim((string) $exportAuart);
            }
            // jika tidak ketemu mapping Export, fallback tetap pakai AUART ZRP
        }
        $params = [
            'view'             => 'po',               // paksa konteks PO
            'werks'            => $werks,
            'auart'            => $auartForReport,    // << kunci: bila ZRP diganti ke Export
            'compact'          => 1,
            'auto_expand'      => 1,                  // sinyal buka T2
            'highlight_kunnr'  => $soInfo->KUNNR,
            'highlight_vbeln'  => $soInfo->VBELN,
            'highlight_bstnk'  => $soInfo->BSTNK,
            'search_term'      => $term,
            // kompatibilitas lama jika ada JS membaca 'auto'
            'auto'             => 1,
        ];

        // 5) Enkripsi & redirect ke route po.report
        $encrypted = Crypt::encrypt($params);

        return redirect()->route('po.report', [
            'q'                => $encrypted,
            // kirimkan juga param plaintext untuk kenyamanan di sisi view/JS
            'auto_expand'      => 1,
            'highlight_kunnr'  => $soInfo->KUNNR,
            'highlight_vbeln'  => $soInfo->VBELN,
            'highlight_bstnk'  => $soInfo->BSTNK,
        ]);
    }


    public function redirector(Request $request)
    {
        try {
            $raw = (string) $request->input('payload', '');
            $data = json_decode($raw, true);
            if (!is_array($data) || empty($data)) {
                throw new \RuntimeException('Invalid payload data.');
            }

            $route = $data['redirect_to'] ?? 'dashboard';
            unset($data['redirect_to']);

            // Tambahkan whitelist 'highlight_bstnk' juga (kalau nanti diperlukan)
            $whitelist = [
                'view',
                'werks',
                'auart',
                'compact',
                'highlight_kunnr',
                'highlight_vbeln',
                'highlight_bstnk',
                'highlight_posnr',
                'auto_expand',
                'location',
                'type',
            ];

            $clean = [];
            foreach ($whitelist as $k) {
                if (array_key_exists($k, $data) && $data[$k] !== '' && $data[$k] !== null) {
                    $clean[$k] = $data[$k];
                }
            }

            $allowed = ['dashboard', 'po.report', 'so.index'];
            if (!in_array($route, $allowed, true)) $route = 'dashboard';

            // >>> hanya remap untuk SO
            if (isset($clean['auto_expand']) && $route === 'so.index') {
                $clean['auto'] = (string) (int) !!$clean['auto_expand'];
                unset($clean['auto_expand']);
            }

            $q = Crypt::encrypt($clean);

            if ($route === 'po.report') {
                $plain = array_filter([
                    'auto_expand'       => $clean['auto_expand'] ?? null,
                    'highlight_kunnr'   => $clean['highlight_kunnr'] ?? null,
                    'highlight_vbeln'   => $clean['highlight_vbeln'] ?? null,
                    'highlight_posnr'   => $clean['highlight_posnr'] ?? null,
                    'highlight_bstnk'   => $clean['highlight_bstnk'] ?? null,
                ], fn($v) => $v !== null && $v !== '');
                return redirect()->route('po.report', array_merge(['q' => $q], $plain));
            }

            if ($route === 'so.index') {
                return redirect()->route('so.index', ['q' => $q]);
            }

            return redirect()->route('dashboard', ['q' => $q]);
        } catch (\Throwable $e) {
            return redirect()->route('dashboard')->withErrors('Gagal memproses link. Data tidak valid.');
        }
    }

    public function apiDecryptPayload(Request $request)
    {
        try {
            $payload = Crypt::decrypt($request->input('q'));
            return response()->json(['ok' => true, 'data' => $payload]);
        } catch (DecryptException $e) {
            return response()->json(['ok' => false, 'error' => 'Invalid payload'], 422);
        }
    }

    public function apiPoOutsByCustomer(Request $request)
    {
        $request->validate([
            'currency' => 'required|string|in:USD,IDR',
            'location' => 'nullable|string|in:2000,3000',
            'type'      => 'nullable|string|in:lokal,export',
            'auart'      => 'nullable|string',
        ]);

        $currency = $request->query('currency');
        $location = $request->query('location');
        $type      = $request->query('type');
        $auart      = $request->query('auart');

        // --- LOGIKA PENGGABUNGAN ---
        $mapping = DB::table('maping')->get();
        $exportAuartCodes = $mapping->filter(function ($item) {
            $d = strtolower($item->Deskription);
            return Str::contains($d, 'export')
                && !Str::contains($d, 'local')
                && !Str::contains($d, 'replace');
        })->pluck('IV_AUART')->unique()->toArray();
        $replaceAuartCodes = $mapping->filter(function ($item) {
            return Str::contains(strtolower($item->Deskription), 'replace');
        })->pluck('IV_AUART')->unique()->toArray();

        $auartList = [];
        if ($type === 'export') {
            $auartList = array_merge($exportAuartCodes, $replaceAuartCodes);
        } elseif ($auart) {
            $auartList[] = $auart;
        }
        $auartList = array_unique(array_filter($auartList));
        // --- END LOGIKA PENGGABUNGAN ---

        // Basis Query dari t2
        $q = DB::table('so_yppr079_t2 as t2')
            ->join(
                'so_yppr079_t1 as t1',
                DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                '=',
                DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
            )
            ->leftJoin('maping as m', function ($j) {
                $j->on('t2.IV_AUART_PARAM', '=', 'm.IV_AUART')
                    ->on('t2.IV_WERKS_PARAM', '=', 'm.IV_WERKS');
            })
            // Filter hanya yang Outstanding (T1.QTY_BALANCE2 > 0)
            ->whereRaw('CAST(t1.QTY_BALANCE2 AS DECIMAL(18,3)) > 0')
            ->where('t2.WAERK', $currency)
            ->when($location, fn($qq, $v) => $qq->where('t2.IV_WERKS_PARAM', $v));

        // Gunakan AUART List
        if (!empty($auartList)) {
            $q->whereIn('t2.IV_AUART_PARAM', $auartList);
        }

        // Filter tipe (lokal/export) sama seperti di index()
        if ($type === 'lokal') {
            $q->whereIn(DB::raw('(t2.IV_AUART_PARAM, t2.IV_WERKS_PARAM)'), function ($sub) {
                $sub->select('IV_AUART', 'IV_WERKS')->from('maping')->where('Deskription', 'like', '%Local%')
                    ->union(
                        DB::table('so_yppr079_t2')
                            ->select('IV_AUART_PARAM as IV_AUART', 'IV_WERKS_PARAM as IV_WERKS')
                            ->groupBy('IV_AUART_PARAM', 'IV_WERKS_PARAM')
                            ->havingRaw("SUM(CASE WHEN WAERK = 'IDR' THEN 1 ELSE 0 END) > 0 AND SUM(CASE WHEN WAERK = 'USD' THEN 1 ELSE 0 END) = 0")
                    );
            });
        }

        $rows = $q->groupBy('t2.KUNNR', 't2.NAME1', 't2.IV_AUART_PARAM', 'm.Deskription')
            ->selectRaw("
            t2.KUNNR,
            MAX(t2.NAME1) as NAME1,
            t2.IV_AUART_PARAM as AUART,
            COALESCE(m.Deskription, t2.IV_AUART_PARAM) as ORDER_TYPE,
            CAST(SUM(t1.TOTPR) AS DECIMAL(18,2)) as TOTAL_VALUE
        ")
            ->havingRaw('SUM(t1.TOTPR) > 0')
            ->orderByDesc('TOTAL_VALUE')
            ->get();

        $grandTotal = $rows->sum('TOTAL_VALUE');

        return response()->json(['ok' => true, 'data' => $rows, 'grand_total' => (float)$grandTotal]);
    }

    public function apiPoStatusDetails(Request $request)
    {
        return $this->apiSoStatusDetails($request);
    }


    public function apiSmallQtyDetails(Request $request)
    {
        $request->validate([
            'customerName' => 'required|string',
            'locationName' => 'required|string|in:Semarang,Surabaya',
            'type'       => 'nullable|string', // Mengubah validasi menjadi nullable string untuk mencegah kegagalan
        ]);

        $customerName = $request->query('customerName');
        $locationName = $request->query('locationName');
        $type       = $request->query('type');

        $werks = ($locationName === 'Semarang') ? '3000' : '2000';

        // --- LOGIKA PENGGABUNGAN ---
        $mapping = DB::table('maping')->get();
        $exportAuartCodes = $mapping->filter(function ($item) {
            $d = strtolower($item->Deskription);
            return Str::contains($d, 'export')
                && !Str::contains($d, 'local')
                && !Str::contains($d, 'replace');
        })->pluck('IV_AUART')->unique()->toArray();
        $replaceAuartCodes = $mapping->filter(function ($item) {
            return Str::contains(strtolower($item->Deskription), 'replace');
        })->pluck('IV_AUART')->unique()->toArray();

        // Tentukan AUART List: Gabungkan Export + Replace jika AUART yang dikirim termasuk Export codes.
        $auartFromRequest = $request->input('auart');
        $auartList = [$auartFromRequest];
        if (!empty($auartList[0]) && in_array($auartList[0], $exportAuartCodes)) {
            $auartList = array_merge($exportAuartCodes, $replaceAuartCodes);
        }
        $auartList = array_unique(array_filter($auartList));
        // --- END LOGIKA PENGGABUNGAN ---


        $query = DB::table('so_yppr079_t1 as t1')
            ->join('so_yppr079_t2 as t2', DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'))
            ->leftJoin('maping as m', function ($join) {
                $join->on('t2.IV_AUART_PARAM', '=', 'm.IV_AUART')
                    ->on('t2.IV_WERKS_PARAM', '=', 'm.IV_WERKS');
            })
            ->where('t2.NAME1', $customerName)
            ->where('t2.IV_WERKS_PARAM', $werks)
            ->where('t1.QTY_BALANCE2', '>', 0)
            ->where('t1.QTY_BALANCE2', '<=', 5)
            ->whereRaw('CAST(t1.QTY_GI AS DECIMAL(18,3)) > 0'); // <<< FILTER HANYA JIKA SHIPPED > 0

        // Tambahkan filter AUART yang relevan
        if (!empty($auartList)) {
            $query->whereIn('t2.IV_AUART_PARAM', $auartList);
        }

        $items = $query->select(
            't2.VBELN',
            't2.BSTNK',
            DB::raw("TRIM(LEADING '0' FROM t1.POSNR) as POSNR"),
            't1.MAKTX',
            't1.KWMENG',
            't1.QTY_GI',
            't1.QTY_BALANCE2',
            't1.KALAB',
            't1.KALAB2'
        )
            ->orderBy('t2.VBELN', 'asc')
            ->orderByRaw('LPAD(TRIM(CAST(t1.POSNR AS CHAR)), 6, "0")')
            ->get();

        return response()->json(['ok' => true, 'data' => $items]);
    }


    public function apiSoStatusDetails(Request $request)
    {
        $request->validate([
            'status'        => 'required|string|in:overdue,due_this_week,on_time',
            'location'      => 'nullable|string|in:2000,3000',
            'type'          => 'nullable|string|in:lokal,export',
        ]);

        $status         = $request->query('status');
        $location       = $request->query('location');
        $type           = $request->query('type');

        // --- LOGIKA PENGGABUNGAN ---
        $mapping = DB::table('maping')->get();
        $exportAuartCodes = $mapping->filter(function ($item) {
            $d = strtolower($item->Deskription);
            return Str::contains($d, 'export')
                && !Str::contains($d, 'local')
                && !Str::contains($d, 'replace');
        })->pluck('IV_AUART')->unique()->toArray();
        $replaceAuartCodes = $mapping->filter(function ($item) {
            return Str::contains(strtolower($item->Deskription), 'replace');
        })->pluck('IV_AUART')->unique()->toArray();
        // --- END LOGIKA PENGGABUNGAN ---

        $safeEdatu = "
        COALESCE(
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y'),
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d')
        )";

        $base = DB::table('so_yppr079_t2 as t2');

        if ($type === 'lokal') {
            $base->whereIn(DB::raw('(t2.IV_AUART_PARAM, t2.IV_WERKS_PARAM)'), function ($query) {
                $query->select('IV_AUART', 'IV_WERKS')->from('maping')->where('Deskription', 'like', '%Local%')
                    ->union(
                        DB::table('so_yppr079_t2')
                            ->select('IV_AUART_PARAM as IV_AUART', 'IV_WERKS_PARAM as IV_WERKS')
                            ->groupBy('IV_AUART_PARAM', 'IV_WERKS_PARAM')
                            ->havingRaw("SUM(CASE WHEN WAERK = 'IDR' THEN 1 ELSE 0 END) > 0 AND SUM(CASE WHEN WAERK = 'USD' THEN 1 ELSE 0 END) = 0")
                    );
            });
        } elseif ($type === 'export') {
            $auartToQuery = array_merge($exportAuartCodes, $replaceAuartCodes);
            $base->whereIn('t2.IV_AUART_PARAM', $auartToQuery);
        }

        $base->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc))
            ->join(
                'so_yppr079_t1 as t1',
                DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                '=',
                DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
            );

        if ($status === 'overdue') {
            $base->whereRaw("{$safeEdatu} < CURDATE()");
        } elseif ($status === 'due_this_week') {
            $base->whereRaw("{$safeEdatu} >= CURDATE() AND {$safeEdatu} <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
        } else {
            $base->whereRaw("{$safeEdatu} > DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
        }

        $rows = $base
            ->groupBy('t2.VBELN', 't2.BSTNK', 't2.NAME1', 't2.IV_WERKS_PARAM', 't2.IV_AUART_PARAM')
            ->selectRaw("
                t2.VBELN,
                t2.BSTNK,
                t2.NAME1,
                t2.IV_WERKS_PARAM,
                t2.IV_AUART_PARAM,
                DATE_FORMAT(MIN({$safeEdatu}), '%d-%m-%Y') AS EDATU, 
                DATEDIFF(CURDATE(), MIN({$safeEdatu})) AS OVERDUE_DAYS 
            ")
            ->orderByRaw("MIN({$safeEdatu}) ASC")
            ->get();

        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function exportSmallQtyPdf(Request $request)
    {
        $validated = $request->validate([
            'customerName' => 'required|string',
            'locationName' => 'required|string|in:Semarang,Surabaya',
            'type'          => 'nullable|string', // Mengubah validasi menjadi nullable string
        ]);

        $customerName = $validated['customerName'];
        $locationName = $validated['locationName'];
        $type           = $validated['type'] ?? null;
        $werks          = $locationName === 'Semarang' ? '3000' : '2000';

        // --- LOGIKA PENGGABUNGAN ---
        $mapping = DB::table('maping')->get();
        $exportAuartCodes = $mapping->filter(function ($item) {
            $d = strtolower($item->Deskription);
            return Str::contains($d, 'export')
                && !Str::contains($d, 'local')
                && !Str::contains($d, 'replace');
        })->pluck('IV_AUART')->unique()->toArray();
        $replaceAuartCodes = $mapping->filter(function ($item) {
            return Str::contains(strtolower($item->Deskription), 'replace');
        })->pluck('IV_AUART')->unique()->toArray();

        // Tentukan AUART List: Gabungkan Export + Replace jika AUART yang dikirim termasuk Export codes.
        $auartFromRequest = $request->input('auart');
        $auartList = [$auartFromRequest];

        if (!empty($auartList[0]) && in_array($auartList[0], $exportAuartCodes)) {
            $auartList = array_merge($exportAuartCodes, $replaceAuartCodes);
        }
        $auartList = array_unique(array_filter($auartList));
        // --- END LOGIKA PENGGABUNGAN ---


        // Query export
        $q = DB::table('so_yppr079_t1 as t1')
            ->join('so_yppr079_t2 as t2', DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'))
            ->leftJoin('maping as m', function ($j) {
                $j->on('t2.IV_AUART_PARAM', '=', 'm.IV_AUART')
                    ->on('t2.IV_WERKS_PARAM', '=', 'm.IV_WERKS');
            })
            ->where('t2.NAME1', $customerName)
            ->where('t2.IV_WERKS_PARAM', $werks)
            ->where('t1.QTY_BALANCE2', '>', 0)
            ->where('t1.QTY_BALANCE2', '<=', 5)
            ->whereRaw('CAST(t1.QTY_GI AS DECIMAL(18,3)) > 0'); // FILTER HANYA JIKA SHIPPED > 0

        // Tambahkan filter AUART yang relevan
        if (!empty($auartList)) {
            $q->whereIn('t2.IV_AUART_PARAM', $auartList);
        }

        $items = $q->select(
            't2.BSTNK as PO',
            't2.VBELN as SO',
            DB::raw("TRIM(LEADING '0' FROM t1.POSNR) as POSNR"),
            't1.MAKTX',
            't1.KWMENG',
            't1.QTY_GI',
            't1.QTY_BALANCE2',
            't1.KALAB', // <<< BARU
            't1.KALAB2' // <<< BARU
        )
            ->orderBy('t2.VBELN')
            ->orderByRaw('LPAD(TRIM(CAST(t1.POSNR AS CHAR)), 6, "0")')
            ->get();

        $totals = [
            'total_item' => $items->count(),
            'total_po'   => $items->pluck('PO')->filter()->unique()->count(),
        ];

        $meta = [
            'customerName' => $customerName,
            'locationName' => $locationName,
            'type'       => $type,
            'generatedAt'      => now()->format('d-m-Y'),
        ];

        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            if ($items->isEmpty()) {
                $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('po_report.small-qty-pdf', [
                    'items' => collect(),
                    'meta' => $meta,
                    'totals' => ['total_item' => 0, 'total_po' => 0],
                ])->setPaper('a4', 'portrait');
            } else {
                $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('po_report.small-qty-pdf', [
                    'items' => $items,
                    'meta' => $meta,
                    'totals' => $totals,
                ])->setPaper('a4', 'portrait');
            }

            $filename = 'SmallQty_' . $locationName . '_' . Str::slug($customerName) . '.pdf';
            return $pdf->stream($filename);
        }

        return view('po_report.small-qty-pdf', [
            'items' => $items,
            'meta' => $meta,
            'totals' => $totals,
        ]);
    }

    public function apiPoRemarkItems(Request $request)
    {
        $request->validate([
            'location' => 'nullable|string|in:2000,3000',
            'type'     => 'nullable|string|in:lokal,export',
            'auart'    => 'nullable|string',
            'vbeln'    => 'nullable|string',
        ]);

        $location = $request->query('location');
        $type     = $request->query('type');
        $auart    = $request->query('auart');
        $vbeln    = trim((string) $request->query('vbeln'));

        // Ambil mapping utk logika Export+Replace (konsisten dgn controller ini)
        $mapping = DB::table('maping')->get();
        $exportAuartCodes = $mapping->filter(function ($item) {
            $d = strtolower($item->Deskription);
            return Str::contains($d, 'export') && !Str::contains($d, 'local') && !Str::contains($d, 'replace');
        })->pluck('IV_AUART')->unique()->toArray();
        $replaceAuartCodes = $mapping->filter(function ($item) {
            return Str::contains(strtolower($item->Deskription), 'replace');
        })->pluck('IV_AUART')->unique()->toArray();

        $rows = DB::table('item_remarks_po as ir')
            // =========================================================================
            // PERUBAHAN UTAMA: LEFT JOIN -> INNER JOIN
            // INNER JOIN ke t1 untuk memastikan item PO/SO masih ada di so_yppr079_t1
            // =========================================================================
            ->join('so_yppr079_t1 as t1', function ($j) {
                $j->on(DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(ir.VBELN AS CHAR))'))
                    ->on(DB::raw('LPAD(TRIM(CAST(t1.POSNR AS CHAR)),6,"0")'), '=', DB::raw('LPAD(TRIM(CAST(ir.POSNR AS CHAR)),6,"0")'));
            })
            // LEFT JOIN ke t2 untuk mendapatkan data PO/KUNNR
            ->leftJoin('so_yppr079_t2 as t2', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(ir.VBELN AS CHAR))'))
            ->selectRaw("
            TRIM(ir.VBELN) AS VBELN,
            TRIM(ir.POSNR) AS POSNR,
            COALESCE(t2.BSTNK,'') AS BSTNK,         -- PO
            COALESCE(t1.MATNR,'') AS MATNR,
            COALESCE(t1.MAKTX,'') AS MAKTX,
            COALESCE(t1.WAERK,'') AS WAERK,
            COALESCE(t1.TOTPR,0) AS TOTPR,
            ir.IV_WERKS_PARAM,
            ir.IV_AUART_PARAM,
            ir.remark,
            ir.created_at,
            COALESCE(t2.KUNNR,'') AS KUNNR
        ")
            ->whereNotNull('ir.remark')->whereRaw('TRIM(ir.remark) <> ""')
            ->when($location, fn($q, $v) => $q->where('ir.IV_WERKS_PARAM', $v))
            ->when($auart,    fn($q, $v) => $q->where('ir.IV_AUART_PARAM', $v))
            ->when($vbeln !== '', fn($q) => $q->whereRaw('TRIM(CAST(ir.VBELN AS CHAR)) = TRIM(?)', [$vbeln]))
            ->when($type, function ($q) use ($type, $exportAuartCodes, $replaceAuartCodes) {
                if ($type === 'lokal') {
                    $q->join('maping as m', function ($j) {
                        $j->on('ir.IV_AUART_PARAM', '=', 'm.IV_AUART')
                            ->on('ir.IV_WERKS_PARAM', '=', 'm.IV_WERKS');
                    })->where('m.Deskription', 'like', '%Local%');
                } elseif ($type === 'export') {
                    $q->whereIn('ir.IV_AUART_PARAM', array_unique(array_merge($exportAuartCodes, $replaceAuartCodes)));
                }
            })
            ->orderBy('ir.VBELN')
            ->orderByRaw('LPAD(TRIM(CAST(ir.POSNR AS CHAR)),6,"0")')
            ->get();

        return response()->json(['ok' => true, 'data' => $rows]);
    }
}
