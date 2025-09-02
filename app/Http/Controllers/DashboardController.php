<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Customer yang harus diabaikan total (tidak tampil, tidak dihitung).
     */
    protected array $blacklistedCustomers = [
        'OVADIA DESIGN GROUP',
        'WOODBRIGHT ASIA',
        'SAMPLE CUSTOMER',
    ];

    /**
     * Terapkan filter blacklist ke Query Builder.
     */
    protected function applyBlacklist($query, string $alias = 't2')
    {
        if (empty($this->blacklistedCustomers)) {
            return $query;
        }

        $quoted = array_map(
            fn($n) => DB::getPdo()->quote(mb_strtoupper(trim($n))),
            $this->blacklistedCustomers
        );

        $col = "UPPER(TRIM({$alias}.NAME1))";
        return $query->whereRaw("{$col} NOT IN (" . implode(',', $quoted) . ")");
    }

    public function index(Request $request)
    {
        // 1. Mengambil parameter dari URL
        $werks = $request->query('werks');
        $auart = $request->query('auart');
        $location = $request->query('location');
        $type = $request->query('type');

        // 2. Menentukan apakah akan menampilkan tabel laporan atau halaman utama
        $show = $request->filled('werks') && $request->filled('auart');
        $compact = $request->boolean('compact', $show);

        // 3. Mengambil data untuk sidebar menu
        $mapping = DB::table('maping')
            ->select('IV_WERKS', 'IV_AUART', 'Deskription')
            ->orderBy('IV_WERKS')->orderBy('IV_AUART')
            ->get()
            ->groupBy('IV_WERKS')
            ->map(fn($g) => $g->unique('IV_AUART')->values());

        // 4. Inisialisasi variabel
        $rows = null;
        $selectedDescription = '';
        $chartData = [];
        $selectedLocationName = 'All Locations';
        $selectedTypeName = 'All Types';

        $safeEdatu = "
    COALESCE(
        STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y'),
        STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d')
    )";

        // 5. Logika utama
        if ($show) {
            $query = DB::table('so_yppr079_t2 as t2')
                ->leftJoin(DB::raw('(
                    SELECT t2_inner.KUNNR, SUM(t1.TOTPR) AS TOTAL_TOTPR
                    FROM so_yppr079_t2 AS t2_inner
                    LEFT JOIN so_yppr079_t1 AS t1
                    ON TRIM(CAST(t1.VBELN AS CHAR)) = TRIM(CAST(t2_inner.VBELN AS CHAR))
                    WHERE t2_inner.IV_WERKS_PARAM = ' . DB::getPdo()->quote($werks) . '
                    AND t2_inner.IV_AUART_PARAM = ' . DB::getPdo()->quote($auart) . '
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
                ->orderBy('NAME1');
            $query = $this->applyBlacklist($query, 't2');
            $rows = $query->paginate(25)->withQueryString();
            $selectedMapping = DB::table('maping')
                ->where('IV_WERKS', $werks)
                ->where('IV_AUART', $auart)
                ->first();
            $selectedDescription = $selectedMapping->Deskription ?? '';
        } else {
            // =================================================================
            // BLOK DASHBOARD UTAMA
            // =================================================================
            $today = now()->startOfDay();

            if ($location === '2000') $selectedLocationName = 'Surabaya';
            if ($location === '3000') $selectedLocationName = 'Semarang';

            $baseQuery = DB::table('so_yppr079_t2 as t2');

            // [MODIFIKASI FINAL v2] Logika filter disempurnakan sesuai aturan baru
            if ($type === 'lokal') {
                $selectedTypeName = 'Lokal';
                $baseQuery->whereIn(DB::raw('(t2.IV_AUART_PARAM, t2.IV_WERKS_PARAM)'), function ($query) {
                    // Kondisi 1: Ambil semua Tipe SO yang deskripsinya 'Local'
                    $query->select('IV_AUART', 'IV_WERKS')->from('maping')->where('Deskription', 'like', '%Local%')
                        // Kondisi 2: GABUNGKAN dengan Tipe SO yang murni IDR (tidak ada USD sama sekali)
                        ->union(
                            DB::table('so_yppr079_t2')
                                ->select('IV_AUART_PARAM as IV_AUART', 'IV_WERKS_PARAM as IV_WERKS')
                                ->groupBy('IV_AUART_PARAM', 'IV_WERKS_PARAM')
                                ->havingRaw("SUM(CASE WHEN WAERK = 'IDR' THEN 1 ELSE 0 END) > 0 AND SUM(CASE WHEN WAERK = 'USD' THEN 1 ELSE 0 END) = 0")
                        );
                });
            } elseif ($type === 'export') {
                $selectedTypeName = 'Export';
                // Subquery untuk mendapatkan daftar Tipe SO yang eksplisit 'Local'
                $explicitlyLocalTypes = DB::table('maping')->select('IV_AUART', 'IV_WERKS')->where('Deskription', 'like', '%Local%');

                $baseQuery->whereIn(DB::raw('(t2.IV_AUART_PARAM, t2.IV_WERKS_PARAM)'), function ($query) {
                    // Ambil semua Tipe SO yang punya transaksi USD
                    $query->select('IV_AUART_PARAM', 'IV_WERKS_PARAM')
                        ->from('so_yppr079_t2')
                        ->groupBy('IV_AUART_PARAM', 'IV_WERKS_PARAM')
                        ->havingRaw("SUM(CASE WHEN WAERK = 'USD' THEN 1 ELSE 0 END) > 0");
                })
                    // KECUALIKAN Tipe SO yang sudah diidentifikasi sebagai 'Local'
                    ->whereNotIn(DB::raw('(t2.IV_AUART_PARAM, t2.IV_WERKS_PARAM)'), $explicitlyLocalTypes);
            }


            // Terapkan filter Lokasi (tidak berubah)
            $baseQuery->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc));

            // Terapkan blacklist pelanggan (tidak berubah)
            $baseQuery = $this->applyBlacklist($baseQuery, 't2');


            // --- Kalkulasi untuk KPI Cards ---
            $kpiQuery = (clone $baseQuery)
                ->leftJoin('so_yppr079_t1 as t1', DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'));

            $chartData['kpi'] = [
                'total_outstanding_value_usd' => (clone $kpiQuery)->where('t2.WAERK', 'USD')->sum('t1.TOTPR'),
                'total_outstanding_value_idr' => (clone $kpiQuery)->where('t2.WAERK', 'IDR')->sum('t1.TOTPR'),
                'total_outstanding_so'        => (clone $kpiQuery)->distinct()->count('t2.VBELN'),
                'total_overdue_so'            => (clone $kpiQuery)->whereRaw("{$safeEdatu} < ?", [$today])->distinct()->count('t2.VBELN'),
            ];
            if ($chartData['kpi']['total_outstanding_so'] > 0) {
                $chartData['kpi']['overdue_rate'] = ($chartData['kpi']['total_overdue_so'] / $chartData['kpi']['total_outstanding_so']) * 100;
            } else {
                $chartData['kpi']['overdue_rate'] = 0;
            }


            // --- Kalkulasi untuk SO Status Chart ---
            $chartData['so_status'] = [
                'overdue'       => $chartData['kpi']['total_overdue_so'],
                'due_this_week' => (clone $baseQuery)
                    ->whereRaw("{$safeEdatu} BETWEEN ? AND ?", [$today, $today->copy()->addDays(7)])
                    ->distinct()->count('t2.VBELN'),
                'on_time'       => (clone $baseQuery)
                    ->whereRaw("{$safeEdatu} > ?", [$today->copy()->addDays(7)])
                    ->distinct()->count('t2.VBELN'),
            ];


            // --- Kalkulasi untuk Outstanding by Location (Tidak berubah) ---
            $chartData['outstanding_by_location'] = DB::table('so_yppr079_t2 as t2')
                ->join('so_yppr079_t1 as t1', DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'))
                ->select(
                    DB::raw("CASE WHEN t2.IV_WERKS_PARAM = '2000' THEN 'Surabaya' ELSE 'Semarang' END as location"),
                    't2.WAERK as currency',
                    DB::raw('SUM(t1.TOTPR) as total_value'),
                    DB::raw('COUNT(DISTINCT t2.VBELN) as so_count')
                )
                ->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc))
                ->when(true, fn($q) => $this->applyBlacklist($q, 't2'))
                ->groupBy('location', 'currency')
                ->get();


            // --- Kalkulasi untuk Top Customers (Tidak berubah) ---
            $chartData['top_customers_value_usd'] = (clone $baseQuery)
                ->join('so_yppr079_t1 as t1', DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'))
                ->select('t2.NAME1', DB::raw('SUM(t1.TOTPR) as total_value'), DB::raw('COUNT(DISTINCT t2.VBELN) as so_count'))
                ->where('t2.WAERK', 'USD')
                ->groupBy('t2.NAME1')
                ->having('total_value', '>', 0)
                ->orderByDesc('total_value')
                ->limit(5)
                ->get();

            $chartData['top_customers_value_idr'] = (clone $baseQuery)
                ->join('so_yppr079_t1 as t1', DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'))
                ->select('t2.NAME1', DB::raw('SUM(t1.TOTPR) as total_value'), DB::raw('COUNT(DISTINCT t2.VBELN) as so_count'))
                ->where('t2.WAERK', 'IDR')
                ->groupBy('t2.NAME1')
                ->having('total_value', '>', 0)
                ->orderByDesc('total_value')
                ->limit(5)
                ->get();

            $chartData['top_customers_overdue'] = (clone $baseQuery)
                ->select('t2.NAME1', DB::raw('COUNT(DISTINCT t2.VBELN) as overdue_count'), DB::raw('GROUP_CONCAT(DISTINCT t2.IV_WERKS_PARAM ORDER BY t2.IV_WERKS_PARAM ASC SEPARATOR ", ") as locations'))
                ->whereRaw("{$safeEdatu} < CURDATE()")
                ->groupBy('t2.NAME1')
                ->orderByDesc('overdue_count')
                ->limit(5)
                ->get();


            // --- Kalkulasi untuk Tabel Analisis Kinerja ---
            $performanceQueryBase = DB::table('maping as m')
                ->join('so_yppr079_t2 as t2', function ($join) {
                    $join->on('m.IV_WERKS', '=', 't2.IV_WERKS_PARAM')
                        ->on('m.IV_AUART', '=', 't2.IV_AUART_PARAM');
                })
                ->leftJoin('so_yppr079_t1 as t1', DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'));

            // [MODIFIKASI FINAL v2] Terapkan filter yang sama pada query tabel kinerja
            $typesToFilter = null;
            if ($type === 'lokal' || $type === 'export') {
                $cloneForFilter = (clone $baseQuery)->select('t2.IV_AUART_PARAM', 't2.IV_WERKS_PARAM')->distinct();
                $typesToFilter = $cloneForFilter->get()->map(function ($item) {
                    return $item->IV_AUART_PARAM . '-' . $item->IV_WERKS_PARAM;
                })->toArray();
            }

            if ($typesToFilter !== null) {
                $performanceQueryBase->whereIn(DB::raw("CONCAT(m.IV_AUART, '-', m.IV_WERKS)"), $typesToFilter);
            }

            $performanceQuery = $performanceQueryBase->select(
                'm.Deskription',
                'm.IV_WERKS',
                DB::raw('COUNT(DISTINCT t2.VBELN) as total_so'),
                DB::raw("SUM(CASE WHEN t2.WAERK = 'IDR' THEN t1.TOTPR ELSE 0 END) as total_value_idr"),
                DB::raw("SUM(CASE WHEN t2.WAERK = 'USD' THEN t1.TOTPR ELSE 0 END) as total_value_usd"),
                DB::raw("COUNT(DISTINCT CASE WHEN {$safeEdatu} < CURDATE() THEN t2.VBELN ELSE NULL END) as overdue_so_count"),
                DB::raw("COUNT(DISTINCT CASE WHEN DATEDIFF(CURDATE(), {$safeEdatu}) BETWEEN 1 AND 30 THEN t2.VBELN ELSE NULL END) as overdue_1_30"),
                DB::raw("COUNT(DISTINCT CASE WHEN DATEDIFF(CURDATE(), {$safeEdatu}) BETWEEN 31 AND 60 THEN t2.VBELN ELSE NULL END) as overdue_31_60"),
                DB::raw("COUNT(DISTINCT CASE WHEN DATEDIFF(CURDATE(), {$safeEdatu}) BETWEEN 61 AND 90 THEN t2.VBELN ELSE NULL END) as overdue_61_90"),
                DB::raw("COUNT(DISTINCT CASE WHEN DATEDIFF(CURDATE(), {$safeEdatu}) > 90 THEN t2.VBELN ELSE NULL END) as overdue_over_90")
            )
                ->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc))
                ->when(true, fn($q) => $this->applyBlacklist($q, 't2'))
                ->groupBy('m.IV_WERKS', 'm.Deskription')
                ->orderBy('m.IV_WERKS')->orderBy('m.Deskription')
                ->get();
            $chartData['so_performance_analysis'] = $performanceQuery;


            // --- Kalkulasi untuk Chart Kuantitas Kecil ---
            $smallQtyByCustomerQuery = (clone $baseQuery)
                ->join('so_yppr079_t1 as t1', DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'))
                ->select('t2.NAME1', 't2.IV_WERKS_PARAM', DB::raw('COUNT(t1.POSNR) as item_count'))
                ->where('t1.QTY_BALANCE2', '>', 0)
                ->where('t1.QTY_BALANCE2', '<=', 5)
                ->groupBy('t2.NAME1', 't2.IV_WERKS_PARAM')
                ->orderBy('t2.NAME1')
                ->get();
            $chartData['small_qty_by_customer'] = $smallQtyByCustomerQuery;
        }

        // 6. Kirim ke view
        return view('dashboard', [
            'mapping'               => $mapping,
            'selected'              => ['werks' => $werks, 'auart' => $auart],
            'selectedDescription'   => $selectedDescription,
            'rows'                  => $rows,
            'compact'               => $compact,
            'show'                  => $show,
            'chartData'             => $chartData,
            'selectedLocation'      => $location,
            'selectedLocationName'  => $selectedLocationName,
            'selectedType'          => $type,
            'selectedTypeName'      => $selectedTypeName,
        ]);
    }

    // Metode lain tidak diubah...
    public function indexAlternative(Request $request)
    {
        $werks = $request->query('werks');
        $auart = $request->query('auart');

        $show = $request->filled('werks') && $request->filled('auart');
        $compact = $request->boolean('compact', $show);

        $mapping = DB::table('maping')
            ->select('IV_WERKS', 'IV_AUART', 'Deskription')
            ->orderBy('IV_WERKS')->orderBy('IV_AUART')
            ->get()
            ->groupBy('IV_WERKS')
            ->map(fn($g) => $g->unique('IV_AUART')->values());

        $rows = null;
        $selectedDescription = '';

        if ($show) {
            $customers = DB::table('so_yppr079_t2')
                ->select('KUNNR', 'NAME1', 'WAERK')
                ->where('IV_WERKS_PARAM', $werks)
                ->where('IV_AUART_PARAM', $auart);

            $customers = $this->applyBlacklist($customers, 'so_yppr079_t2')
                ->groupBy('KUNNR', 'NAME1', 'WAERK')
                ->orderBy('NAME1')
                ->paginate(25)->withQueryString();

            foreach ($customers as $customer) {
                $totpr = DB::table('so_yppr079_t2 as t2')
                    ->join('so_yppr079_t1 as t1', function ($join) {
                        $join->on(DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'));
                    })
                    ->where('t2.KUNNR', $customer->KUNNR)
                    ->where('t2.IV_WERKS_PARAM', $werks)
                    ->where('t2.IV_AUART_PARAM', $auart)
                    ->sum('t1.TOTPR');

                $customer->TOTPR = $totpr ?: 0;
            }

            $rows = $customers;

            $selectedMapping = DB::table('maping')
                ->where('IV_WERKS', $werks)
                ->where('IV_AUART', $auart)
                ->first();
            $selectedDescription = $selectedMapping->Deskription ?? '';
        }

        return view('dashboard', [
            'mapping'               => $mapping,
            'selected'              => ['werks' => $werks, 'auart' => $auart],
            'selectedDescription'   => $selectedDescription,
            'rows'                  => $rows,
            'compact'               => $compact,
            'show'                  => $show,
        ]);
    }

    public function apiSmallQtyDetails(Request $request)
    {
        // 1. Validasi input
        $request->validate([
            'customerName' => 'required|string',
            'locationName' => 'required|string|in:Semarang,Surabaya'
        ]);

        $customerName = $request->query('customerName');
        $locationName = $request->query('locationName');
        $type = $request->query('type'); // Filter 'type' (lokal/export) yang sedang aktif

        // Konversi nama lokasi ke kode 'werks'
        $werks = ($locationName === 'Semarang') ? '3000' : '2000';

        // 2. Buat query dasar untuk mengambil item
        $query = DB::table('so_yppr079_t1 as t1')
            ->join('so_yppr079_t2 as t2', DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'))
            ->join('maping as m', function ($join) {
                $join->on('t2.IV_AUART_PARAM', '=', 'm.IV_AUART')
                    ->on('t2.IV_WERKS_PARAM', '=', 'm.IV_WERKS');
            })
            ->where('t2.NAME1', $customerName)       // Filter berdasarkan nama customer yang di-klik
            ->where('t2.IV_WERKS_PARAM', $werks)     // Filter berdasarkan lokasi bar yang di-klik (Semarang/Surabaya)
            ->where('t1.QTY_BALANCE2', '>', 0)       // Filter utama untuk kuantitas
            ->where('t1.QTY_BALANCE2', '<=', 5);

        // 3. Terapkan filter 'type' (lokal/export) yang sama dengan di halaman utama
        // Ini untuk memastikan data yang ditampilkan konsisten
        if ($type === 'lokal') {
            $query->where(function ($q) {
                $q->where('m.Deskription', 'like', '%Local%')
                    ->orWhere('m.Deskription', 'like', '%Replace%'); // Sesuaikan jika 'Replace' masuk lokal
            });
        } elseif ($type === 'export') {
            $query->where('m.Deskription', 'like', '%Export%');
        }

        // 4. Ambil data yang dibutuhkan dan urutkan
        $items = $query->select(
            't2.VBELN',         // No SO
            't2.BSTNK',         // No PO Customer
            't1.POSNR',         // No Item
            't1.MAKTX',         // Deskripsi Material
            't1.KWMENG',        // Kuantitas Order
            't1.QTY_BALANCE2'   // Kuantitas Outstanding
        )
            ->orderBy('t2.VBELN', 'asc')
            ->orderBy('t1.POSNR', 'asc')
            ->get();

        // 5. Kembalikan sebagai JSON
        return response()->json(['ok' => true, 'data' => $items]);
    }

    public function search(Request $request)
    {
        $request->validate([
            'term' => 'required|string|max:100'
        ]);

        $term_searched = $request->input('term');

        // [MODIFIKASI] Menggunakan TRIM untuk membersihkan spasi dari kolom database
        $so_info = DB::table('so_yppr079_t2')
            ->where(function ($query) use ($term_searched) {
                // Cari berdasarkan VBELN (SO) setelah di-TRIM
                $query->whereRaw('TRIM(CAST(VBELN AS CHAR)) = ?', [$term_searched])
                    // ATAU cari berdasarkan BSTNK (PO) setelah di-TRIM
                    ->orWhereRaw('TRIM(CAST(BSTNK AS CHAR)) = ?', [$term_searched]);
            })
            ->select('IV_WERKS_PARAM', 'IV_AUART_PARAM', 'KUNNR', 'VBELN')
            ->first();

        if ($so_info) {
            // Jika ditemukan, siapkan parameter untuk redirect
            $params = [
                'werks'           => $so_info->IV_WERKS_PARAM,
                'auart'           => $so_info->IV_AUART_PARAM,
                'compact'         => 1,
                'highlight_kunnr' => $so_info->KUNNR,
                'highlight_vbeln' => $so_info->VBELN,
                'search_term'     => $term_searched,
            ];

            return redirect()->route('dashboard', $params);
        }

        // Jika tidak ditemukan, kembali dengan pesan error
        return back()->withErrors(['term' => 'Nomor PO atau SO "' . $term_searched . '" tidak ditemukan.'])->withInput();
    }

    public function apiT2(Request $req)
    {
        $kunnr = (string) $req->query('kunnr');
        $werks = $req->query('werks');
        $auart = $req->query('auart');

        if (!$kunnr) {
            return response()->json(['ok' => false, 'error' => 'kunnr missing'], 400);
        }

        $rows = DB::table('so_yppr079_t2 as t2')
            ->select([
                't2.VBELN',
                't2.BSTNK',
                't2.WAERK',
                't2.EDATU',
                DB::raw('(SELECT COALESCE(SUM(t1.TOTPR), 0) FROM so_yppr079_t1 as t1 WHERE TRIM(CAST(t1.VBELN AS CHAR)) = TRIM(CAST(t2.VBELN AS CHAR))) as TOTPR')
            ])
            ->distinct()
            ->where(function ($q) use ($kunnr) {
                $q->where('t2.KUNNR', $kunnr)
                    ->orWhereRaw('TRIM(CAST(t2.KUNNR AS CHAR)) = TRIM(?)', [$kunnr])
                    ->orWhereRaw('CAST(TRIM(t2.KUNNR) AS UNSIGNED) = CAST(TRIM(?) AS UNSIGNED)', [$kunnr]);
            })
            ->when(strlen((string)$werks) > 0, function ($q) use ($werks) {
                $q->where(function ($qq) use ($werks) {
                    $qq->where('t2.WERKS', $werks)
                        ->orWhere('t2.IV_WERKS_PARAM', $werks)
                        ->orWhereRaw('TRIM(CAST(t2.WERKS AS CHAR)) = TRIM(?)', [$werks])
                        ->orWhereRaw('TRIM(CAST(t2.IV_WERKS_PARAM AS CHAR)) = TRIM(?)', [$werks]);
                });
            })
            ->when(strlen((string)$auart) > 0, function ($q) use ($auart) {
                $q->where(function ($qq) use ($auart) {
                    $qq->where('t2.AUART', $auart)
                        ->orWhere('t2.IV_AUART_PARAM', $auart)
                        ->orWhereRaw('TRIM(t2.AUART) = TRIM(?)', [$auart])
                        ->orWhereRaw('TRIM(t2.IV_AUART_PARAM) = TRIM(?)', [$auart]);
                });
            });

        $rows = $this->applyBlacklist($rows, 't2')->get();

        $today = new \DateTime();
        $today->setTime(0, 0, 0);

        foreach ($rows as $row) {
            $overdue = 0;
            $row->FormattedEdatu = '';

            if (!empty($row->EDATU) && $row->EDATU !== '0000-00-00') {
                try {
                    $edatuDate = \DateTime::createFromFormat('Y-m-d', $row->EDATU);
                    if ($edatuDate) {
                        $row->FormattedEdatu = $edatuDate->format('d-m-Y');
                        $edatuDate->setTime(0, 0, 0);
                        $diff = $today->diff($edatuDate);
                        $overdue = (int)$diff->days;
                        if ($diff->invert) $overdue = -$overdue;
                    }
                } catch (\Exception $e) {
                    $overdue = 0;
                }
            }
            $row->Overdue = $overdue;

            $items = DB::table('so_yppr079_t1')
                ->select('KWMENG', 'QTY_BALANCE2')
                ->whereRaw('TRIM(CAST(VBELN AS CHAR)) = TRIM(?)', [$row->VBELN])
                ->when($werks, fn($q) => $q->where('IV_WERKS_PARAM', $werks))
                ->when($auart, fn($q) => $q->where('IV_AUART_PARAM', $auart))
                ->get();

            $itemPercentages = [];
            foreach ($items as $item) {
                $qtyPo = (float) $item->KWMENG;
                $outstanding = (float) $item->QTY_BALANCE2;
                if ($qtyPo > 0) {
                    $itemPercentages[] = ($outstanding / $qtyPo) * 100;
                }
            }
            $row->ShortagePercentage = count($itemPercentages) ? array_sum($itemPercentages) / count($itemPercentages) : 0;
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

        $matchVbeln = function ($q) use ($vbeln) {
            $q->where('t1.VBELN', $vbeln)
                ->orWhereRaw('TRIM(CAST(t1.VBELN AS CHAR)) = TRIM(?)', [$vbeln])
                ->orWhereRaw('CAST(TRIM(t1.VBELN) AS UNSIGNED) = CAST(TRIM(?) AS UNSIGNED)', [$vbeln]);
        };

        $rows = DB::table('so_yppr079_t1 as t1')
            ->join('so_yppr079_t2 as tx', DB::raw('TRIM(CAST(tx.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'))
            ->select('t1.POSNR', 't1.MATNR', 't1.MAKTX', 't1.KWMENG', 't1.QTY_GI', 't1.QTY_BALANCE2', 't1.NETPR', 't1.TOTPR', 't1.NETWR', 't1.WAERK')
            ->where($matchVbeln)
            ->when(strlen((string)$werks) > 0, function ($q) use ($werks) {
                $q->where(function ($qq) use ($werks) {
                    $qq->where('t1.WERKS', $werks)
                        ->orWhere('t1.IV_WERKS_PARAM', $werks)
                        ->orWhereRaw('TRIM(CAST(t1.WERKS AS CHAR)) = TRIM(?)', [$werks])
                        ->orWhereRaw('TRIM(CAST(t1.IV_WERKS_PARAM AS CHAR)) = TRIM(?)', [$werks]);
                });
            })
            ->when(strlen((string)$auart) > 0, function ($q) use ($auart) {
                $q->where(function ($qq) use ($auart) {
                    $qq->where('t1.AUART', $auart)
                        ->orWhere('t1.IV_AUART_PARAM', $auart)
                        ->orWhereRaw('TRIM(t1.AUART) = TRIM(?)', [$auart])
                        ->orWhereRaw('TRIM(t1.IV_AUART_PARAM) = TRIM(?)', [$auart]);
                });
            });

        $rows = $this->applyBlacklist($rows, 'tx')
            ->orderByRaw('LPAD(TRIM(CAST(t1.POSNR AS CHAR)), 6, "0")')
            ->get();

        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function debugTotpr(Request $request)
    {
        $kunnr = $request->query('kunnr');

        $t2Data = DB::table('so_yppr079_t2')
            ->select('VBELN', 'KUNNR', 'NAME1')
            ->where('KUNNR', $kunnr);

        $t2Data = $this->applyBlacklist($t2Data, 'so_yppr079_t2')->get();

        $result = [];
        foreach ($t2Data as $row) {
            $t1Sum = DB::table('so_yppr079_t1')
                ->whereRaw('TRIM(CAST(VBELN AS CHAR)) = TRIM(?)', [$row->VBELN])
                ->sum('TOTPR');

            $result[] = [
                'VBELN' => $row->VBELN,
                'KUNNR' => $row->KUNNR,
                'NAME1' => $row->NAME1,
                'TOTPR_SUM' => $t1Sum
            ];
        }

        return response()->json($result);
    }
}
