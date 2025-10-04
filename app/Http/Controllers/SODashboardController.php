<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;

class SODashboardController extends Controller
{
    public function index(Request $request)
    {
        // Dekripsi q (jika ada) lalu merge
        $decryptedParams = [];
        if ($request->has('q')) {
            try {
                $decryptedParams = Crypt::decrypt($request->query('q'));
                if (!is_array($decryptedParams)) $decryptedParams = [];
            } catch (DecryptException $e) {
                return redirect()->route('so.dashboard')->withErrors('Link tidak valid atau telah kadaluwarsa.');
            }
        }
        if (!empty($decryptedParams)) {
            $request->merge($decryptedParams);
        }

        // Filter SO dashboard
        $location = $request->query('location'); // '2000'|'3000'|null
        $type     = $request->query('type');     // 'lokal'|'export'|null
        $auart    = $request->query('auart');    // optional

        // Mapping (sidebar)
        $mapping = DB::table('maping')
            ->select('IV_WERKS', 'IV_AUART', 'Deskription')
            ->orderBy('IV_WERKS')->orderBy('IV_AUART')
            ->get()
            ->groupBy('IV_WERKS')
            ->map(fn($g) => $g->unique('IV_AUART')->values());

        $chartData = $this->getSoDashboardData($request);

        return view('so_dashboard.dashboard', [
            'mapping'          => $mapping,
            'chartData'        => $chartData,
            'selectedLocation' => $location,
            'selectedType'     => $type,
            'selectedAuart'    => $auart, // FIX: Menambahkan $auart
            'selectedTypeName' => $type === 'lokal' ? 'Lokal' : ($type === 'export' ? 'Export' : 'All Types'),
            'view'             => 'so', // fix ke 'so'
        ]);
    }

    /* ================== Semua API SO dipindahkan ke sini ================== */

    public function apiSoOutsByCustomer(Request $request)
    {
        $request->validate([
            'currency' => 'required|string|in:USD,IDR',
            'location' => 'nullable|string|in:2000,3000',
            'type'     => 'nullable|string|in:lokal,export',
            'auart'    => 'nullable|string',
        ]);

        $currency = $request->query('currency');
        $location = $request->query('location');
        $type     = $request->query('type');
        $auart    = $request->query('auart');

        // Basis query: item outstanding yang OVERDUE
        $base = DB::table('so_yppr079_t2 as t2')
            ->join(
                'so_yppr079_t1 as t1',
                DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                '=',
                DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
            )
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) > 0')
            ->when($location, fn($q, $v) => $q->where('t2.IV_WERKS_PARAM', $v));

        $base->where('t2.WAERK', $currency);

        // Helper filter AUART/TYPE DIDEFINISIKAN ULANG UNTUK MENGHINDARI BUG JOIN GANDA
        $applyTypeOrAuart = function ($q) use ($type, $auart) {
            if (!empty($auart)) {
                $q->where("t2.IV_AUART_PARAM", $auart);
                return;
            }
            if ($type === 'lokal' || $type === 'export') {
                // Menggunakan alias m2 untuk menghindari konflik join
                $q->join('maping as m2', function ($j) {
                    $j->on("t2.IV_AUART_PARAM", '=', 'm2.IV_AUART')
                        ->on("t2.IV_WERKS_PARAM", '=', 'm2.IV_WERKS');
                });
                if ($type === 'lokal') {
                    $q->where('m2.Deskription', 'like', '%Local%');
                } else { // export
                    $q->where('m2.Deskription', 'like', '%Export%')
                        ->where('m2.Deskription', 'not like', '%Replace%')
                        ->where('m2.Deskription', 'not like', '%Local%');
                }
            }
        };

        // Terapkan filter tipe/auart
        $applyTypeOrAuart($base);

        // Ambil deskripsi order type untuk label (JOIN AKHIR)
        // Gunakan LEFT JOIN agar tidak menghilangkan data SO yang tidak punya mapping
        $base->leftJoin('maping as mp', function ($j) {
            $j->on('t2.IV_AUART_PARAM', '=', 'mp.IV_AUART')
                ->on('t2.IV_WERKS_PARAM', '=', 'mp.IV_WERKS');
        });

        $rows = $base
            ->groupBy('t2.KUNNR', 't2.NAME1', 't2.IV_AUART_PARAM')
            ->selectRaw("
        t2.KUNNR,
        MAX(t2.NAME1) AS NAME1,
        t2.IV_AUART_PARAM AS AUART,
        MAX(COALESCE(mp.Deskription, t2.IV_AUART_PARAM)) AS ORDER_TYPE,
        CAST(SUM(CAST(t1.TOTPR2 AS DECIMAL(18,2))) AS DECIMAL(18,2)) AS TOTAL_VALUE
    ")
            ->havingRaw('SUM(t1.TOTPR2) > 0')
            ->orderByDesc('TOTAL_VALUE')
            ->limit(50000)
            ->get();

        return response()->json([
            'ok'          => true,
            'data'        => $rows,
            'grand_total' => (float) ($rows->sum('TOTAL_VALUE') ?? 0),
            'metric'      => 'TOTPR2_overdue',
        ]);
    }

    public function apiSoRemarkSummary(Request $request)
    {
        $request->validate([
            'location' => 'nullable|string|in:2000,3000',
            'type'     => 'nullable|string|in:lokal,export',
            'auart'    => 'nullable|string',
        ]);

        $location = $request->query('location'); // 2000|3000|null
        $type     = $request->query('type');     // lokal|export|null
        $auart    = $request->query('auart');

        // Samakan “AUART efektif” dengan dashboard SO lain
        $effectiveAuart = $auart;
        if (!$effectiveAuart && $location && $type) {
            $effectiveAuart = DB::table('maping')
                ->where('IV_WERKS', $location)
                ->when($type === 'export', fn($q) => $q->where('Deskription', 'like', '%Export%')
                    ->where('Deskription', 'not like', '%Replace%')
                    ->where('Deskription', 'not like', '%Local%'))
                ->when($type === 'lokal',  fn($q) => $q->where('Deskription', 'like', '%Local%'))
                ->orderBy('IV_AUART')->value('IV_AUART');
        }

        // Basis remark items
        $base = DB::table('item_remarks')
            ->whereNotNull('remark')->whereRaw('TRIM(remark) <> ""')
            ->when($location,       fn($q, $v) => $q->where('IV_WERKS_PARAM', $v))
            ->when($effectiveAuart, fn($q, $v) => $q->where('IV_AUART_PARAM', $v));

        $totalItemRemarks = (clone $base)->count();
        $totalSoWithRemarks = (clone $base)->distinct()->count('VBELN');

        // TOP SO by jumlah item remark (opsional untuk tooltip/legend)
        $bySo = (clone $base)
            ->selectRaw('VBELN, COUNT(*) AS item_count')
            ->groupBy('VBELN')->orderByDesc('item_count')->get();

        return response()->json([
            'ok' => true,
            'data' => [
                'total_item_remarks'    => $totalItemRemarks,
                'total_so_with_remarks' => $totalSoWithRemarks,
                'top_so'                => $bySo,
            ]
        ]);
    }

    public function apiSoRemarkItems(Request $request)
    {
        $request->validate([
            'location' => 'nullable|string|in:2000,3000',
            'type'     => 'nullable|string|in:lokal,export',
            'auart'    => 'nullable|string',
            'vbeln'    => 'nullable|string', // kalau diisi, hanya 1 SO
        ]);

        $location = $request->query('location');
        $type     = $request->query('type');
        $auart    = $request->query('auart');
        $vbeln    = trim((string)$request->query('vbeln'));

        // [LOGIKA LAMA DIHAPUS] Blok 'effectiveAuart' yang bergantung pada $location dihapus.
        // Filter akan diterapkan langsung di query utama di bawah ini.

        $rows = DB::table('item_remarks as ir')
            ->leftJoin('so_yppr079_t1 as t1', function ($j) {
                $j->on(DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(ir.VBELN AS CHAR))'))
                    ->on(DB::raw('LPAD(TRIM(CAST(t1.POSNR AS CHAR)),6,"0")'), '=', DB::raw('LPAD(TRIM(CAST(ir.POSNR AS CHAR)),6,"0")'));
            })
            ->leftJoin('so_yppr079_t2 as t2', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(ir.VBELN AS CHAR))'))
            ->selectRaw("
            TRIM(ir.VBELN) AS VBELN,
            TRIM(ir.POSNR) AS POSNR,
            COALESCE(t1.MATNR,'') AS MATNR,
            COALESCE(t1.MAKTX,'') AS MAKTX,
            COALESCE(t1.WAERK,'') AS WAERK,
            COALESCE(t1.TOTPR,0) AS TOTPR,
            ir.IV_WERKS_PARAM,
            ir.IV_AUART_PARAM,
            ir.remark,
            ir.created_at,
            COALESCE(t2.KUNNR, '') AS KUNNR 
        ")
            ->whereNotNull('ir.remark')->whereRaw('TRIM(ir.remark) <> ""')
            ->when($location, fn($q, $v) => $q->where('ir.IV_WERKS_PARAM', $v))
            ->when($auart,    fn($q, $v) => $q->where('ir.IV_AUART_PARAM', $v)) // Filter by auart tetap berfungsi
            ->when($vbeln !== '', fn($q) => $q->whereRaw('TRIM(CAST(ir.VBELN AS CHAR)) = TRIM(?)', [$vbeln]))

            // [DIUBAH] Tambahkan logika filter untuk 'type' (lokal/export) di sini
            ->when($type, function ($query, $typeValue) {
                $query->join('maping as m', function ($join) {
                    $join->on('ir.IV_AUART_PARAM', '=', 'm.IV_AUART')
                        ->on('ir.IV_WERKS_PARAM', '=', 'm.IV_WERKS');
                });
                if ($typeValue === 'lokal') {
                    $query->where('m.Deskription', 'like', '%Local%');
                } elseif ($typeValue === 'export') {
                    $query->where('m.Deskription', 'like', '%Export%')
                        ->where('m.Deskription', 'not like', '%Replace%')
                        ->where('m.Deskription', 'not like', '%Local%');
                }
            })

            ->orderBy('ir.VBELN')->orderByRaw('LPAD(TRIM(CAST(ir.POSNR AS CHAR)),6,"0")')
            ->limit(2000)
            ->get();

        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function apiSoBottlenecksDetails(Request $request)
    {
        $today    = Carbon::today();
        $window  = (int) $request->query('window', 7);
        $endDate = (clone $today)->addDays($window);

        $request->validate([
            'location' => 'nullable|string|in:2000,3000',
            'type'     => 'nullable|string|in:lokal,export',
            'auart'    => 'nullable|string',
            'window'   => 'nullable|integer|min:1|max:60',
        ]);

        $location = $request->query('location');
        $type     = $request->query('type');
        $auart    = $request->query('auart');

        // Parser tanggal aman untuk EDATU di T2
        $safeEdatuT2 = "COALESCE(
        STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
        STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
    )";

        // Basis: LANGSUNG dari t1+t2 (tanpa t3)
        $base = DB::table('so_yppr079_t1 as t1')
            ->join(
                'so_yppr079_t2 as t2',
                DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                '=',
                DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
            )
            // item outstanding (ada qty) & shortage
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(15,3)) > 0')
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(15,3)) > CAST(t1.KALAB2 AS DECIMAL(15,3))')
            // window: due >= hari ini & <= hari ini + N
            ->whereRaw("{$safeEdatuT2} >= CURDATE() AND {$safeEdatuT2} <= DATE_ADD(CURDATE(), INTERVAL ? DAY)", [$window])
            // filter lokasi/auart jika ada
            ->when($location, fn($q, $v) => $q->where('t2.IV_WERKS_PARAM', $v))
            ->when($auart,    fn($q, $v) => $q->where('t2.IV_AUART_PARAM', $v));

        // Filter TYPE (lokal/export) via tabel maping
        if ($type === 'lokal' || $type === 'export') {
            $base->join('maping as m', function ($j) {
                $j->on('t2.IV_AUART_PARAM', '=', 'm.IV_AUART')
                    ->on('t2.IV_WERKS_PARAM', '=', 'm.IV_WERKS');
            });
            if ($type === 'lokal') {
                $base->where('m.Deskription', 'like', '%Local%');
            } else {
                $base->where('m.Deskription', 'like', '%Export%')
                    ->where('m.Deskription', 'not like', '%Replace%')
                    ->where('m.Deskription', 'not like', '%Local%');
            }
        }

        // Keluarkan SO-level (VBELN) + due paling awal
        $rows = $base
            ->groupBy('t2.VBELN', 't2.BSTNK', 't2.NAME1', 't2.IV_WERKS_PARAM', 't2.IV_AUART_PARAM')
            ->selectRaw("
            t2.VBELN,
            t2.BSTNK,
            t2.NAME1,
            t2.IV_WERKS_PARAM,
            t2.IV_AUART_PARAM,
            DATE_FORMAT(MIN({$safeEdatuT2}), '%Y-%m-%d') AS due_date
        ")
            ->orderByRaw("MIN({$safeEdatuT2}) ASC")
            ->limit(2000)
            ->get();

        return response()->json([
            'ok'   => true,
            'data' => $rows,
            'window_info' => [
                'start' => $today->toDateString(),
                'end'   => $endDate->toDateString(),
                'days'  => $window,
            ],
        ]);
    }

    public function apiSoUrgencyDetails(Request $request)
    {
        // ... (Fungsi ini tetap sama)
        $request->validate([
            'status'   => 'required|string|in:overdue_over_30,overdue_1_30,due_this_week,on_time',
            'location' => 'nullable|string|in:2000,3000',
            'type'     => 'nullable|string|in:lokal,export',
            'auart'    => 'nullable|string',
        ]);

        $status   = $request->query('status');
        $location = $request->query('location');
        $type     = $request->query('type');
        $auart    = $request->query('auart');

        // Parser tanggal aman – SAMA dgn getSoDashboardData (basis T2)
        $safeEdatuT2 = "COALESCE(
        STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
        STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
    )";

        // Helper filter AUART/TYPE – SAMA dgn getSoDashboardData
        $applyTypeOrAuart = function ($q, string $alias) use ($type, $auart) {
            if (!empty($auart)) {
                $q->where("{$alias}.IV_AUART_PARAM", $auart);
                return;
            }
            if ($type === 'lokal' || $type === 'export') {
                $q->join('maping as m', function ($j) use ($alias) {
                    $j->on("{$alias}.IV_AUART_PARAM", '=', 'm.IV_AUART')
                        ->on("{$alias}.IV_WERKS_PARAM", '=', 'm.IV_WERKS');
                });
                if ($type === 'lokal') {
                    $q->where('m.Deskription', 'like', '%Local%');
                } else { // export
                    $q->where('m.Deskription', 'like', '%Export%')
                        ->where('m.Deskription', 'not like', '%Replace%')
                        ->where('m.Deskription', 'not like', '%Local%');
                }
            }
        };

        // Basis data: T2 + join T1 (hanya outstanding: PACKG > 0)
        $base = DB::table('so_yppr079_t2 as t2')
            ->join('so_yppr079_t1 as t1', DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'))
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) <> 0')
            ->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc));

        $applyTypeOrAuart($base, 't2');

        // Filter status sama persis dgn donut
        if ($status === 'overdue_over_30') {
            $base->whereRaw("DATEDIFF(CURDATE(), {$safeEdatuT2}) > 30");
        } elseif ($status === 'overdue_1_30') {
            $base->whereRaw("DATEDIFF(CURDATE(), {$safeEdatuT2}) BETWEEN 1 AND 30");
        } elseif ($status === 'due_this_week') {
            $base->whereRaw("{$safeEdatuT2} BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
        } else { // on_time
            $base->whereRaw("{$safeEdatuT2} > DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
        }

        // Keluarkan unik per SO (VBELN) dengan due date terawal – SAMA formatnya dgn donut
        $rows = $base
            ->groupBy('t2.VBELN', 't2.BSTNK', 't2.NAME1', 't2.IV_WERKS_PARAM', 't2.IV_AUART_PARAM')
            ->selectRaw("
            t2.VBELN,
            t2.BSTNK,
            t2.NAME1,
            t2.IV_WERKS_PARAM,
            t2.IV_AUART_PARAM,
            DATE_FORMAT(MIN({$safeEdatuT2}), '%Y-%m-%d') AS due_date
        ")
            ->orderByRaw("MIN({$safeEdatuT2}) ASC")
            ->get();

        return response()->json(['ok' => true, 'data' => $rows]);
    }

    /* ================== Helper SO dashboard ================== */
    private function getSoDashboardData(Request $request)
    {
        $window    = (int) $request->query('window', 7);
        $location = $request->query('location'); // '2000' | '3000' | null
        $type      = $request->query('type');    // 'lokal' | 'export' | null
        $auart    = $request->query('auart');   // optional

        $today     = now()->startOfDay();
        $startWeek = now()->startOfWeek(\Carbon\Carbon::MONDAY)->startOfDay(); // inclusive
        $endWeekEx = (clone $startWeek)->addWeek();                          // exclusive

        // Parser tanggal aman (alias t2)
        $safeEdatuT2 = "COALESCE(
        STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
        STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
    )";

        // Helper filter AUART/TYPE (alias dinamis)
        $applyTypeOrAuart = function ($q, string $alias) use ($type, $auart) {
            if (!empty($auart)) {
                $q->where("{$alias}.IV_AUART_PARAM", $auart);
                return;
            }
            if ($type === 'lokal' || $type === 'export') {
                $q->join('maping as m', function ($j) use ($alias) {
                    $j->on("{$alias}.IV_AUART_PARAM", '=', 'm.IV_AUART')
                        ->on("{$alias}.IV_WERKS_PARAM", '=', 'm.IV_WERKS');
                });
                if ($type === 'lokal') {
                    $q->where('m.Deskription', 'like', '%Local%');
                } else { // export
                    $q->where('m.Deskription', 'like', '%Export%')
                        ->where('m.Deskription', 'not like', '%Replace%')
                        ->where('m.Deskription', 'not like', '%Local%');
                }
            }
        };

        $chartData = [];

        /**
         * ===== Basis SO-LEVEL (T2) untuk METRIK JUMLAH (distinct VBELN),
         * namun “outstanding” ditentukan oleh EXISTS item T1 PACKG > 0
         */
        $relevantSoT2 = DB::table('so_yppr079_t2 as t2')
            ->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc))
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('so_yppr079_t1 as t1x')
                    ->whereColumn('t1x.VBELN', 't2.VBELN')
                    ->whereRaw('CAST(t1x.PACKG AS DECIMAL(18,3)) > 0');
            });
        $applyTypeOrAuart($relevantSoT2, 't2');

        // ===== KPI: jumlah SO outstanding & overdue (distinct VBELN)
        $totalOutstandingSo = (clone $relevantSoT2)->distinct()->count('t2.VBELN');
        $totalOverdueSo     = (clone $relevantSoT2)
            ->whereRaw("{$safeEdatuT2} < ?", [$today])
            ->distinct()->count('t2.VBELN');

        /**
         * ===== Basis ITEM OUTSTANDING untuk nilai (TOTPR2/TOTPR) – JOIN T1 (PACKG > 0)
         */
        $packItemsBase = DB::table('so_yppr079_t2 as t2')
            ->join(
                'so_yppr079_t1 as t1',
                DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                '=',
                DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
            )
            ->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc))
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) > 0');
        $applyTypeOrAuart($packItemsBase, 't2');

        /**
         * ===== KPI “Outs Value Packing USD/IDR” — LOGIKA OVERDUE VALUE (SAMA DENGAN CODE LAMA)
         * SUM(TOTPR2) untuk item outstanding yang OVERDUE (EDATU < today), per currency
         */
        $overduePackingAgg = (clone $packItemsBase)
            ->selectRaw("
    TRIM(t1.WAERK) as cur,
    CAST(SUM(CAST(t1.TOTPR2 AS DECIMAL(18,2))) AS DECIMAL(18,2)) as amt
")
            ->groupBy('cur')
            ->get();

        $kpiPackingUsd = (float) (optional($overduePackingAgg->firstWhere('cur', 'USD'))->amt ?? 0);
        $kpiPackingIdr = (float) (optional($overduePackingAgg->firstWhere('cur', 'IDR'))->amt ?? 0);

        // (opsional) expose juga ke FE spt “so_report_totals” agar konsisten dipakai widget lain
        $chartData['so_report_totals'] = ['usd' => $kpiPackingUsd, 'idr' => $kpiPackingIdr];

        /**
         * ===== KPI: Value to Packing This Week (HANYA minggu ini, TOTPR2)
         */
        $weekItemsBase = (clone $packItemsBase)
            ->whereRaw("{$safeEdatuT2} >= ? AND {$safeEdatuT2} < ?", [$startWeek, $endWeekEx]);

        $valueToShipAgg = (clone $weekItemsBase)->selectRaw("
        CAST(SUM(CASE WHEN TRIM(t1.WAERK)='USD' THEN CAST(t1.TOTPR2 AS DECIMAL(18,2)) ELSE 0 END) AS DECIMAL(18,2)) AS usd,
        CAST(SUM(CASE WHEN TRIM(t1.WAERK)='IDR' THEN CAST(t1.TOTPR2 AS DECIMAL(18,2)) ELSE 0 END) AS DECIMAL(18,2)) AS idr
    ")->first();

        $valueToShipUsd = (float) ($valueToShipAgg->usd ?? 0);
        $valueToShipIdr = (float) ($valueToShipAgg->idr ?? 0);

        // ===== Potential Bottlenecks (tetap)
        $potentialBottlenecksQuery = DB::table('so_yppr079_t1 as t1')
            ->join(
                'so_yppr079_t2 as t2',
                DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                '=',
                DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
            )
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(15,3)) > 0')
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(15,3)) > CAST(t1.KALAB2 AS DECIMAL(15,3))')
            ->whereRaw("{$safeEdatuT2} >= CURDATE() AND {$safeEdatuT2} <= DATE_ADD(CURDATE(), INTERVAL ? DAY)", [$window])
            ->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc));
        $applyTypeOrAuart($potentialBottlenecksQuery, 't2');

        $chartData['kpi'] = [
            // KPI Outs Value Packing (overdue TOTPR2) — match SO Report
            'total_outstanding_value_usd' => $kpiPackingUsd,
            'total_outstanding_value_idr' => $kpiPackingIdr,

            'total_outstanding_so'          => $totalOutstandingSo,
            'total_overdue_so'              => $totalOverdueSo,
            'overdue_rate'                  => $totalOutstandingSo > 0 ? ($totalOverdueSo / $totalOutstandingSo) * 100 : 0,

            // minggu berjalan (TOTPR2)
            'value_to_ship_this_week_usd' => $valueToShipUsd,
            'value_to_ship_this_week_idr' => $valueToShipIdr,

            'potential_bottlenecks'         => (clone $potentialBottlenecksQuery)->distinct()->count('t1.VBELN'),
        ];

        /**
         * ===== Value to Packing vs Overdue by Location — pakai TOTPR2
         * Total = SUM(TOTPR2) outstanding; Overdue = SUM(TOTPR2) overdue
         */
        $totalByLoc = (clone $packItemsBase)
            ->selectRaw("
            CASE WHEN t2.IV_WERKS_PARAM = '2000' THEN 'Surabaya' ELSE 'Semarang' END AS location,
            CAST(SUM(CASE WHEN t1.WAERK='IDR' THEN t1.TOTPR2 ELSE 0 END) AS DECIMAL(18,2)) AS total_idr,
            CAST(SUM(CASE WHEN t1.WAERK='USD' THEN t1.TOTPR2 ELSE 0 END) AS DECIMAL(18,2)) AS total_usd
        ")
            ->groupBy('location')
            ->get()
            ->keyBy('location');

        $overdueByLoc = (clone $packItemsBase)
            ->whereRaw("{$safeEdatuT2} < CURDATE()")
            ->selectRaw("
            CASE WHEN t2.IV_WERKS_PARAM = '2000' THEN 'Surabaya' ELSE 'Semarang' END AS location,
            CAST(SUM(CASE WHEN t1.WAERK='IDR' THEN t1.TOTPR2 ELSE 0 END) AS DECIMAL(18,2)) AS overdue_idr,
            CAST(SUM(CASE WHEN t1.WAERK='USD' THEN t1.TOTPR2 ELSE 0 END) AS DECIMAL(18,2)) AS overdue_usd
        ")
            ->groupBy('location')
            ->get()
            ->keyBy('location');

        $locations = ['Surabaya', 'Semarang'];
        $chartData['value_by_location_status'] = collect($locations)->map(function ($loc) use ($totalByLoc, $overdueByLoc) {
            $t_idr = (float) ($totalByLoc[$loc]->total_idr ?? 0);
            $t_usd = (float) ($totalByLoc[$loc]->total_usd ?? 0);
            $o_idr = (float) ($overdueByLoc[$loc]->overdue_idr ?? 0);
            $o_usd = (float) ($overdueByLoc[$loc]->overdue_usd ?? 0);

            $on_idr = max($t_idr - $o_idr, 0);
            $on_usd = max($t_usd - $o_usd, 0);

            return (object)[
                'location'          => $loc,
                'on_time_value'     => $on_idr + $on_usd,
                'overdue_value'     => $o_idr + $o_usd,
                'on_time_breakdown' => ['idr' => $on_idr, 'usd' => $on_usd],
                'overdue_breakdown' => ['idr' => $o_idr, 'usd' => $o_usd],
            ];
        });

        /**
         * ===== Donut Urgency / Aging — T2 ONLY (dengan EXISTS T1)
         */
        $agingBase = DB::table('so_yppr079_t2 as t2')
            ->join(
                'so_yppr079_t1 as t1',
                DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                '=',
                DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
            )
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) <> 0')
            ->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc));
        $applyTypeOrAuart($agingBase, 't2');

        // LOGIKA AGING ANALYSIS (DONUT) SUDAH BENAR, MENGHITUNG DISTINCT SO (VBELN)
        $chartData['aging_analysis'] = [
            'overdue_over_30' => (clone $agingBase)
                ->whereRaw("DATEDIFF(CURDATE(), {$safeEdatuT2}) > 30")
                ->distinct()->count('t2.VBELN'),

            'overdue_1_30' => (clone $agingBase)
                ->whereRaw("DATEDIFF(CURDATE(), {$safeEdatuT2}) BETWEEN 1 AND 30")
                ->distinct()->count('t2.VBELN'),

            'due_this_week' => (clone $agingBase)
                ->whereRaw("{$safeEdatuT2} BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")
                ->distinct()->count('t2.VBELN'),

            'on_time' => (clone $agingBase)
                ->whereRaw("{$safeEdatuT2} > DATE_ADD(CURDATE(), INTERVAL 7 DAY)")
                ->distinct()->count('t2.VBELN'),
        ];

        /**
         * ===== Top 5 Customers (VALUE overdue) — SAMAKAN DENGAN SO REPORT
         * Pakai TOTPR2 item outstanding yang overdue, agregasi per KUNNR.
         */
        $topOverdueBase = (clone $packItemsBase)
            ->whereRaw("{$safeEdatuT2} < CURDATE()") // PENTING: Filter Overdue
            ->groupBy('t2.KUNNR', 't1.WAERK')
            ->selectRaw("
            t2.KUNNR,
            MAX(t2.NAME1) AS NAME1,
            t1.WAERK,
            CAST(SUM(t1.TOTPR2) AS DECIMAL(18,2)) AS total_value,
            CAST(SUM(CASE WHEN t2.IV_WERKS_PARAM = '2000' THEN t1.TOTPR2 ELSE 0 END) AS DECIMAL(18,2)) AS sby_value,
            CAST(SUM(CASE WHEN t2.IV_WERKS_PARAM = '3000' THEN t1.TOTPR2 ELSE 0 END) AS DECIMAL(18,2)) AS smg_value
            ")
            ->havingRaw('SUM(t1.TOTPR2) > 0')
            ->orderByDesc('total_value');

        $chartData['top_customers_value_usd'] = (clone $topOverdueBase)
            ->where('t1.WAERK', 'USD')
            ->limit(5)
            ->get();

        $chartData['top_customers_value_idr'] = (clone $topOverdueBase)
            ->where('t1.WAERK', 'IDR')
            ->limit(5)
            ->get();

        /**
         * ===== List “SO Due This Week” (tabel di UI) — TOTPR2
         */
        $dueThisWeekBySo = DB::table('so_yppr079_t2 as t2')
            ->join(
                'so_yppr079_t1 as t1',
                DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                '=',
                DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
            )
            ->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc))
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) > 0')
            ->whereRaw("{$safeEdatuT2} >= ? AND {$safeEdatuT2} < ?", [$startWeek, $endWeekEx])
            ->groupBy('t2.VBELN', 't2.BSTNK', 't2.NAME1', 't1.WAERK', 't2.IV_WERKS_PARAM', 't2.IV_AUART_PARAM')
            ->selectRaw("
            t2.VBELN, t2.BSTNK, t2.NAME1, t1.WAERK,
            t2.IV_WERKS_PARAM, t2.IV_AUART_PARAM,
            CAST(SUM(t1.TOTPR2) AS DECIMAL(18,2)) AS total_value,
            DATE_FORMAT(MIN({$safeEdatuT2}), '%Y-%m-%d') AS due_date
        ")
            ->orderByDesc('total_value');
        $applyTypeOrAuart($dueThisWeekBySo, 't2');
        $dueThisWeekBySo = $dueThisWeekBySo->get();

        /**
         * ===== Customers Due This Week (agregasi dari item; HARUS match KPI)
         */
        $dueThisWeekByCustomer = DB::table('so_yppr079_t1 as t1')
            ->join(
                'so_yppr079_t2 as t2',
                DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                '=',
                DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
            )
            ->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc))
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) > 0')
            ->whereRaw("{$safeEdatuT2} >= ? AND {$safeEdatuT2} < ?", [$startWeek, $endWeekEx])
            ->groupBy('t2.NAME1', 't1.WAERK')
            ->selectRaw("t2.NAME1, t1.WAERK, CAST(SUM(t1.TOTPR2) AS DECIMAL(18,2)) AS total_value")
            ->orderByDesc('total_value');
        $applyTypeOrAuart($dueThisWeekByCustomer, 't2');
        $dueThisWeekByCustomer = $dueThisWeekByCustomer->get();

        $chartData['due_this_week'] = [
            'start'         => $startWeek->toDateTimeString(),
            'end_excl'      => $endWeekEx->toDateTimeString(),
            'by_so'         => $dueThisWeekBySo,
            'by_customer'   => $dueThisWeekByCustomer,
        ];

        return $chartData;
    }
}
