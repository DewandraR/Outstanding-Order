<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Str;

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
        $type = $request->query('type'); // 'lokal'|'export'|null
        $auart = $request->query('auart'); // optional

        // Mapping (sidebar)
        $mapping = DB::table('maping')
            ->select('IV_WERKS', 'IV_AUART', 'Deskription')
            ->orderBy('IV_WERKS')->orderBy('IV_AUART')
            ->get()
            ->groupBy('IV_WERKS')
            ->map(fn($g) => $g->unique('IV_AUART')->values());

        $chartData = $this->getSoDashboardData($request);

        return view('so_dashboard.dashboard', [
            'mapping' => $mapping,
            'chartData' => $chartData,
            'selectedLocation' => $location,
            'selectedType' => $type,
            'selectedAuart' => $auart,
            'selectedTypeName' => $type === 'lokal' ? 'Lokal' : ($type === 'export' ? 'Export' : 'All Types'),
            'view' => 'so',
        ]);
    }

    /* ================== API Yang Dipertahankan ================== */

    public function apiSoRemarkSummary(Request $request)
    {
        $request->validate([
            'location' => 'nullable|string|in:2000,3000',
            'type' => 'nullable|string|in:lokal,export',
            'auart' => 'nullable|string',
        ]);

        $location = $request->query('location'); // 2000|3000|null
        $type = $request->query('type'); // lokal|export|null
        $auart = $request->query('auart');

        // Samakan “AUART efektif” dengan dashboard SO lain
        $effectiveAuart = $auart;
        if (!$effectiveAuart && $location && $type) {
            $effectiveAuart = DB::table('maping')
                ->where('IV_WERKS', $location)
                ->when($type === 'export', fn($q) => $q->where('Deskription', 'like', '%Export%')
                    ->where('Deskription', 'not like', '%Replace%')
                    ->where('Deskription', 'not like', '%Local%'))
                ->when($type === 'lokal', fn($q) => $q->where('Deskription', 'like', '%Local%'))
                ->orderBy('IV_AUART')->value('IV_AUART');
        }

        // Basis remark items
        $base = DB::table('item_remarks')
            ->whereNotNull('remark')->whereRaw('TRIM(remark) <> ""')
            ->when($location, fn($q, $v) => $q->where('IV_WERKS_PARAM', $v))
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
                'total_item_remarks' => $totalItemRemarks,
                'total_so_with_remarks' => $totalSoWithRemarks,
                'top_so' => $bySo,
            ]
        ]);
    }

    public function apiSoRemarkItems(Request $request)
    {
        $request->validate([
            'location' => 'nullable|string|in:2000,3000',
            'type' => 'nullable|string|in:lokal,export',
            'auart' => 'nullable|string',
            'vbeln' => 'nullable|string', // kalau diisi, hanya 1 SO
        ]);

        $location = $request->query('location');
        $type = $request->query('type');
        $auart = $request->query('auart');
        $vbeln = trim((string)$request->query('vbeln'));
        $rows = DB::table('item_remarks as ir')
            // Ganti LEFT JOIN menjadi INNER JOIN untuk memastikan item SO terkait masih ada di t1
            ->join('so_yppr079_t1 as t1', function ($j) {
                $j->on(DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(ir.VBELN AS CHAR))'))
                    ->on(DB::raw('LPAD(TRIM(CAST(t1.POSNR AS CHAR)),6,"0")'), '=', DB::raw('LPAD(TRIM(CAST(ir.POSNR AS CHAR)),6,"0")'));
            })
            ->leftJoin('so_yppr079_t2 as t2', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(ir.VBELN AS CHAR))'));
        // =========================================================================
        // END: LOGIKA BARU
        // =========================================================================

        $rows = $rows->selectRaw("
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
            ->when($auart, fn($q, $v) => $q->where('ir.IV_AUART_PARAM', $v))
            ->when($vbeln !== '', fn($q) => $q->whereRaw('TRIM(CAST(ir.VBELN AS CHAR)) = TRIM(?)', [$vbeln]))

            // Tambahkan logika filter untuk 'type' (lokal/export) 
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

    public function apiSoUrgencyDetails(Request $request)
    {
        $request->validate([
            'status' => 'required|string|in:overdue_over_30,overdue_1_30,due_this_week,on_time',
            'location' => 'nullable|string|in:2000,3000',
            'type' => 'nullable|string|in:lokal,export',
            'auart' => 'nullable|string',
        ]);

        $status = $request->query('status');
        $location = $request->query('location');
        $type = $request->query('type');
        $auart = $request->query('auart');

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

        // Basis data: T2 + join T1 (hanya outstanding: PACKG <> 0)
        $base = DB::table('so_yppr079_t2 as t2')
            ->join('so_yppr079_t1 as t1', DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'))
            // --- PERUBAHAN DI SINI: Ganti > 0 menjadi <> 0 ---
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) <> 0')
            // --------------------------------------------------
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

    /* ================== Helper SO dashboard (Termasuk KPI Baru) ================== */
    private function getSoDashboardData(Request $request)
    {
        $window = (int) $request->query('window', 7);
        $location = $request->query('location'); // '2000' | '3000' | null
        $type = $request->query('type'); // 'lokal' | 'export' | null
        $auart = $request->query('auart'); // optional

        $today = now()->startOfDay();
        $startWeek = now()->startOfWeek(\Carbon\Carbon::MONDAY)->startOfDay(); // inclusive
        $endWeekEx = (clone $startWeek)->addWeek(); // exclusive

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

        /* =====================================================================
         * Logic untuk KPI Block Baru (Semarang / Surabaya)
         * ===================================================================== */

        // Query Dasar untuk Item Outstanding (PACKG <> 0)
        $allOutstandingItemsBase = DB::table('so_yppr079_t2 as t2')
            ->join(
                'so_yppr079_t1 as t1',
                DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                '=',
                DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
            )
            // --- PERUBAHAN DI SINI: Ganti > 0 menjadi <> 0 ---
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) <> 0')
            // --------------------------------------------------
            ->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc));
        $applyTypeOrAuart($allOutstandingItemsBase, 't2');


        // 1. Outstanding Value dan Count (Total semua item outstanding)
        $totalAgg = (clone $allOutstandingItemsBase)
            ->groupBy('t2.IV_WERKS_PARAM', 't1.WAERK')
            ->selectRaw("
                t2.IV_WERKS_PARAM as werks,
                t1.WAERK as cur,
                CAST(SUM(t1.TOTPR2) AS DECIMAL(18,2)) AS value,
                COUNT(DISTINCT t1.VBELN) AS so_count
            ")
            ->get();

        // 2. Overdue Value dan Count (Item outstanding yang EDATU < hari ini)
        $overdueAgg = (clone $allOutstandingItemsBase)
            ->whereRaw("{$safeEdatuT2} < CURDATE()")
            ->groupBy('t2.IV_WERKS_PARAM', 't1.WAERK')
            ->selectRaw("
                t2.IV_WERKS_PARAM as werks,
                t1.WAERK as cur,
                CAST(SUM(t1.TOTPR2) AS DECIMAL(18,2)) AS value,
                COUNT(DISTINCT t1.VBELN) AS so_count
            ")
            ->get();

        $kpiNew = [];
        $locations = ['3000' => 'smg', '2000' => 'sby'];

        foreach ($locations as $werksCode => $prefix) {
            if ($location && $location != $werksCode) continue;

            // Outstanding
            $usdTotal = $totalAgg->where('werks', $werksCode)->firstWhere('cur', 'USD');
            $idrTotal = $totalAgg->where('werks', $werksCode)->firstWhere('cur', 'IDR');

            $kpiNew["{$prefix}_usd_val"] = (float) ($usdTotal->value ?? 0);
            $kpiNew["{$prefix}_usd_qty"] = (int) ($usdTotal->so_count ?? 0);
            $kpiNew["{$prefix}_idr_val"] = (float) ($idrTotal->value ?? 0);
            $kpiNew["{$prefix}_idr_qty"] = (int) ($idrTotal->so_count ?? 0);

            // Overdue
            $usdOverdue = $overdueAgg->where('werks', $werksCode)->firstWhere('cur', 'USD');
            $idrOverdue = $overdueAgg->where('werks', $werksCode)->firstWhere('cur', 'IDR');

            $kpiNew["{$prefix}_usd_overdue_val"] = (float) ($usdOverdue->value ?? 0);
            $kpiNew["{$prefix}_usd_overdue_qty"] = (int) ($usdOverdue->so_count ?? 0);
            $kpiNew["{$prefix}_idr_overdue_val"] = (float) ($idrOverdue->value ?? 0);
            $kpiNew["{$prefix}_idr_overdue_qty"] = (int) ($idrOverdue->so_count ?? 0);
        }
        $chartData['kpi_new'] = $kpiNew;
        /* =====================================================================
         * END: Logic untuk KPI Block Baru
         * ===================================================================== */


        // Mengosongkan KPI lama karena digantikan oleh kpi_new
        $chartData['kpi'] = [
            'total_outstanding_value_usd' => 0,
            'total_outstanding_value_idr' => 0,
            'total_outstanding_so' => 0,
            'total_overdue_so' => 0,
            'overdue_rate' => 0,
            'value_to_ship_this_week_usd' => 0,
            'value_to_ship_this_week_idr' => 0,
            'potential_bottlenecks' => 0,
        ];

        /* =====================================================================
         * Donut Urgency / Aging — T2 ONLY (dengan EXISTS T1)
         * ===================================================================== */
        $agingBase = DB::table('so_yppr079_t2 as t2')
            ->join(
                'so_yppr079_t1 as t1',
                DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                '=',
                DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
            )
            // --- PERUBAHAN DI SINI: Ganti > 0 menjadi <> 0 ---
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) <> 0')
            // --------------------------------------------------
            ->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc));
        $applyTypeOrAuart($agingBase, 't2');

        // LOGIKA AGING ANALYSIS (DONUT)
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

        /* =====================================================================
         * Top 5 Customers (VALUE overdue) — DIHAPUS
         * ===================================================================== */
        // Menambahkan placeholder untuk menghindari error di Blade / JS
        $chartData['top_customers_value_usd'] = collect([]);
        $chartData['top_customers_value_idr'] = collect([]);


        /* =====================================================================
         * List “SO Due This Week” (tabel di UI) — TOTPR2
         * ===================================================================== */
        $dueThisWeekBySo = DB::table('so_yppr079_t2 as t2')
            ->join(
                'so_yppr079_t1 as t1',
                DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                '=',
                DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
            )
            ->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc))
            // --- PERUBAHAN DI SINI: Ganti > 0 menjadi <> 0 ---
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) <> 0')
            // --------------------------------------------------
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

        /* =====================================================================
         * Customers Due This Week (agregasi dari item; HARUS match KPI)
         * ===================================================================== */
        $dueThisWeekByCustomer = DB::table('so_yppr079_t1 as t1')
            ->join(
                'so_yppr079_t2 as t2',
                DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                '=',
                DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
            )
            ->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc))
            // --- PERUBAHAN DI SINI: Ganti > 0 menjadi <> 0 ---
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) <> 0')
            // --------------------------------------------------
            ->whereRaw("{$safeEdatuT2} >= ? AND {$safeEdatuT2} < ?", [$startWeek, $endWeekEx])
            ->groupBy('t2.NAME1', 't1.WAERK')
            ->selectRaw("t2.NAME1, t1.WAERK, CAST(SUM(t1.TOTPR2) AS DECIMAL(18,2)) AS total_value")
            ->orderByDesc('total_value');
        $applyTypeOrAuart($dueThisWeekByCustomer, 't2');
        $dueThisWeekByCustomer = $dueThisWeekByCustomer->get();

        $chartData['due_this_week'] = [
            'start' => $startWeek->toDateTimeString(),
            'end_excl' => $endWeekEx->toDateTimeString(),
            'by_so' => $dueThisWeekBySo,
            'by_customer' => $dueThisWeekByCustomer,
        ];

        return $chartData;
    }
}
