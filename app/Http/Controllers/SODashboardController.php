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
    /**
     * Helper: Aman-kan parsing EDATU untuk subquery item unik.
     * Mengambil EDATU dari t1 (yang merupakan subquery item unik)
     * @param string $alias Alias tabel atau subquery yang berisi kolom EDATU.
     */
    private function getSafeEdatuForUniqueItem(string $alias): string
    {
        return "COALESCE(
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST({$alias}.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST({$alias}.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
        )";
    }

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
        $base = DB::table('item_remarks as ir')
            ->join('so_yppr079_t1 as t1', function ($j) {
                $j->on(DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(ir.VBELN AS CHAR))'))
                    ->on(DB::raw('LPAD(TRIM(CAST(t1.POSNR AS CHAR)),6,"0")'), '=', DB::raw('LPAD(TRIM(CAST(ir.POSNR AS CHAR)),6,"0")'));
            })
            ->when($location, fn($q, $v) => $q->where('ir.IV_WERKS_PARAM', $v))
            ->when($effectiveAuart, fn($q, $v) => $q->where('ir.IV_AUART_PARAM', $v))
            ->whereNotNull('ir.remark')->whereRaw("TRIM(ir.remark) <> ''")
            // ⬇️ hanya item outstanding
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) <> 0');

        $totalItemRemarks   = (clone $base)->count(); // jumlah baris remark (outstanding)
        $totalSoWithRemarks = (clone $base)->distinct()->count('ir.VBELN');

        $bySo = (clone $base)
            ->selectRaw('ir.VBELN, COUNT(*) AS item_count')
            ->groupBy('ir.VBELN')
            ->orderByDesc('item_count')
            ->get();

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
        // NORMALISASI input, hindari 422
        $location = $request->query('location');
        $auart    = $request->query('auart');
        $vbeln    = trim((string) $request->query('vbeln'));
        $typeIn   = strtolower(trim((string) $request->query('type')));
        $type     = in_array($typeIn, ['lokal', 'export'], true) ? $typeIn : null;

        $rows = DB::table('item_remarks as ir')
            // item harus masih ada & outstanding
            ->join('so_yppr079_t1 as t1', function ($j) {
                $j->on(DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(ir.VBELN AS CHAR))'))
                    ->on(DB::raw('LPAD(TRIM(CAST(t1.POSNR AS CHAR)),6,"0")'), '=', DB::raw('LPAD(TRIM(CAST(ir.POSNR AS CHAR)),6,"0")'));
            })
            ->leftJoin('so_yppr079_t2 as t2', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(ir.VBELN AS CHAR))'))
            // label OT dari mapping (termasuk Replace)
            ->leftJoin('maping as ml', function ($j) {
                $j->on('ir.IV_AUART_PARAM', '=', 'ml.IV_AUART')
                    ->on('ir.IV_WERKS_PARAM', '=', 'ml.IV_WERKS');
            })
            ->whereNotNull('ir.remark')
            ->whereRaw("TRIM(ir.remark) <> ''")
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) <> 0')
            ->when($location, fn($q, $v) => $q->where('ir.IV_WERKS_PARAM', $v))
            ->when($auart,    fn($q, $v) => $q->where('ir.IV_AUART_PARAM', $v))
            ->when($vbeln !== '', fn($q) => $q->whereRaw('TRIM(CAST(ir.VBELN AS CHAR)) = TRIM(?)', [$vbeln]))
            // filter type (ZRP1/ZRP2 dianggap Export)
            ->when($type, function ($q, $typeValue) {
                $q->join('maping as mf', function ($j) {
                    $j->on('ir.IV_AUART_PARAM', '=', 'mf.IV_AUART')
                        ->on('ir.IV_WERKS_PARAM', '=', 'mf.IV_WERKS');
                });
                if ($typeValue === 'lokal') {
                    $q->where('mf.Deskription', 'like', '%Local%');
                } else { // export
                    $q->where(function ($w) {
                        $w->where('mf.Deskription', 'like', '%Export%')
                            ->where('mf.Deskription', 'not like', '%Local%')
                            ->orWhereIn('mf.IV_AUART', ['ZRP1', 'ZRP2']);
                    });
                }
            })
            // [PERBAIKAN DEDUPLIKASI]: Gunakan MAX() dan Group By (VBELN, POSNR, MATNR)
            ->groupBy('ir.VBELN', 'ir.POSNR', 't1.MATNR', 'ir.IV_WERKS_PARAM', 'ir.IV_AUART_PARAM')
            ->selectRaw("
            TRIM(ir.VBELN) AS VBELN,
            TRIM(ir.POSNR) AS POSNR,
            MAX(COALESCE(t1.MATNR,'')) AS MATNR,
            MAX(COALESCE(t1.MAKTX,'')) AS MAKTX,
            MAX(COALESCE(t1.WAERK,'')) AS WAERK,
            MAX(COALESCE(t1.TOTPR,0)) AS TOTPR,
            ir.IV_WERKS_PARAM,
            ir.IV_AUART_PARAM,
            MAX(ir.remark) as remark,
            MAX(ir.created_at) as created_at,
            MAX(COALESCE(t2.KUNNR,'')) AS KUNNR,
            CASE
             WHEN ir.IV_AUART_PARAM='ZRP1' THEN 'KMI Export SBY'
             WHEN ir.IV_AUART_PARAM='ZRP2' THEN 'KMI Export SMG'
             ELSE MAX(COALESCE(ml.Deskription, ir.IV_AUART_PARAM))
            END AS OT_NAME
        ")
            ->orderBy('ir.VBELN')
            ->orderByRaw('LPAD(TRIM(CAST(ir.POSNR AS CHAR)),6,"0")')
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
            if ($type === 'lokal') {
                $q->join('maping as m', function ($j) use ($alias) {
                    $j->on("{$alias}.IV_AUART_PARAM", '=', 'm.IV_AUART')
                        ->on("{$alias}.IV_WERKS_PARAM", '=', 'm.IV_WERKS');
                })->where('m.Deskription', 'like', '%Local%');
            } elseif ($type === 'export') {
                $q->join('maping as m', function ($j) use ($alias) {
                    $j->on("{$alias}.IV_AUART_PARAM", '=', 'm.IV_AUART')
                        ->on("{$alias}.IV_WERKS_PARAM", '=', 'm.IV_WERKS');
                })->where(function ($w) {
                    $w->where('m.Deskription', 'like', '%Export%')
                        ->where('m.Deskription', 'not like', '%Local%')
                        // ⬇️ Export juga mencakup Replace
                        ->orWhereIn('m.IV_AUART', ['ZRP1', 'ZRP2']);
                });
            }
        };

        // Basis data: T2 + join T1 (hanya outstanding: PACKG <> 0)
        // DIBUAT DEDUP AGGREGATION SEBELUMNYA UNTUK MENGHITUNG SO COUNT

        $uniqueItemsAgg = DB::table('so_yppr079_t1 as t1a')
            ->select(
                't1a.VBELN',
                't1a.POSNR',
                't1a.MATNR',
                't1a.EDATU',
                DB::raw('MAX(t1a.PACKG) AS item_outs_qty')
            )
            ->whereRaw('CAST(t1a.PACKG AS DECIMAL(18,3)) <> 0')
            ->when($location, fn($q, $loc) => $q->where('t1a.IV_WERKS_PARAM', $loc))
            ->groupBy('t1a.VBELN', 't1a.POSNR', 't1a.MATNR', 't1a.EDATU');

        $applyTypeOrAuart($uniqueItemsAgg, 't1a');


        // Basis data: T2 JOIN item unik (untuk SO Count)
        $base = DB::table('so_yppr079_t2 as t2')
            ->joinSub($uniqueItemsAgg, 't1', fn($j) => $j->on(DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')));

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
            if ($type === 'lokal') {
                $q->join('maping as m', function ($j) use ($alias) {
                    $j->on("{$alias}.IV_AUART_PARAM", '=', 'm.IV_AUART')
                        ->on("{$alias}.IV_WERKS_PARAM", '=', 'm.IV_WERKS');
                })->where('m.Deskription', 'like', '%Local%');
            } elseif ($type === 'export') {
                $q->join('maping as m', function ($j) use ($alias) {
                    $j->on("{$alias}.IV_AUART_PARAM", '=', 'm.IV_AUART')
                        ->on("{$alias}.IV_WERKS_PARAM", '=', 'm.IV_WERKS');
                })->where(function ($w) {
                    $w->where('m.Deskription', 'like', '%Export%')
                        ->where('m.Deskription', 'not like', '%Local%')
                        // ⬇️ Export juga mencakup Replace
                        ->orWhereIn('m.IV_AUART', ['ZRP1', 'ZRP2']);
                });
            }
        };

        /* =====================================================================
         * DEDUPLIKASI ITEM UNIK (KPI, DONUT, DETAIL)
         * ===================================================================== */

        // Subquery Item Unik (VBELN, POSNR, MATNR)
        $uniqueItemsAgg = DB::table('so_yppr079_t1 as t1a')
            ->select(
                't1a.VBELN',
                't1a.KUNNR',
                't1a.IV_WERKS_PARAM',
                't1a.IV_AUART_PARAM',
                't1a.EDATU',
                't1a.WAERK',
                DB::raw('MAX(t1a.TOTPR2) AS item_total_value'),
                DB::raw('MAX(t1a.PACKG) AS item_outs_qty')
            )
            ->whereRaw('CAST(t1a.PACKG AS DECIMAL(18,3)) <> 0')
            ->groupBy('t1a.VBELN', 't1a.POSNR', 't1a.MATNR', 't1a.KUNNR', 't1a.IV_WERKS_PARAM', 't1a.IV_AUART_PARAM', 't1a.EDATU', 't1a.WAERK');

        // Apply filter type/auart/location ke item unik
        $itemUniqueFiltered = DB::table(DB::raw("({$uniqueItemsAgg->toSql()}) as t_u"))->mergeBindings($uniqueItemsAgg)
            ->when($location, fn($q, $loc) => $q->where('t_u.IV_WERKS_PARAM', $loc));
        $applyTypeOrAuart($itemUniqueFiltered, 't_u');

        // Gabungkan item unik dengan T2 (untuk mengambil data SO header)
        $allOutstandingItemsBase = DB::table('so_yppr079_t2 as t2')
            ->joinSub($itemUniqueFiltered, 't1', function ($j) {
                // Joinkan t2.VBELN dengan item unik
                $j->on(DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'));
            });


        /* =====================================================================
          * Logic untuk KPI Block Baru (Semarang / Surabaya)
          * Dihitung dari items unik (t1)
          * ===================================================================== */

        // 1. Outstanding Value dan Count (Total semua item outstanding)
        $totalAgg = (clone $allOutstandingItemsBase)
            // Agregasi dilakukan berdasarkan WERKS dan CURRENCY dari item unik (t1)
            ->groupBy('t1.IV_WERKS_PARAM', 't1.WAERK')
            ->selectRaw("
                t1.IV_WERKS_PARAM as werks,
                t1.WAERK as cur,
                CAST(SUM(t1.item_total_value) AS DECIMAL(18,2)) AS value,
                COUNT(DISTINCT t1.VBELN) AS so_count
            ")
            ->get();

        // 2. Overdue Value dan Count (Item outstanding yang EDATU < hari ini)
        $overdueAgg = (clone $allOutstandingItemsBase)
            // Filter EDATU menggunakan kolom EDATU dari item unik (t1)
            ->whereRaw($this->getSafeEdatuForUniqueItem('t1') . " < CURDATE()")
            ->groupBy('t1.IV_WERKS_PARAM', 't1.WAERK')
            ->selectRaw("
                t1.IV_WERKS_PARAM as werks,
                t1.WAERK as cur,
                CAST(SUM(t1.item_total_value) AS DECIMAL(18,2)) AS value,
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
         * Donut Urgency / Aging — Dihitung dari SO Header (t2) yang memiliki item unik (t1)
         * ===================================================================== */

        // Logic Donut (SO Count): menggunakan $allOutstandingItemsBase yang sudah didefinisikan (T2 JOIN T1 DEDUP)

        // LOGIKA AGING ANALYSIS (DONUT)
        $chartData['aging_analysis'] = [
            'overdue_over_30' => (clone $allOutstandingItemsBase)
                ->whereRaw("DATEDIFF(CURDATE(), {$safeEdatuT2}) > 30")
                ->distinct()->count('t2.VBELN'),

            'overdue_1_30' => (clone $allOutstandingItemsBase)
                ->whereRaw("DATEDIFF(CURDATE(), {$safeEdatuT2}) BETWEEN 1 AND 30")
                ->distinct()->count('t2.VBELN'),

            'due_this_week' => (clone $allOutstandingItemsBase)
                ->whereRaw("{$safeEdatuT2} BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")
                ->distinct()->count('t2.VBELN'),

            'on_time' => (clone $allOutstandingItemsBase)
                ->whereRaw("{$safeEdatuT2} > DATE_ADD(CURDATE(), INTERVAL 7 DAY)")
                ->distinct()->count('t2.VBELN'),
        ];

        /* =====================================================================
         * List “SO Due This Week” (tabel di UI) — Dihitung dari item unik (t1)
         * ===================================================================== */

        $dueThisWeekBase = DB::table('so_yppr079_t2 as t2')
            ->joinSub($itemUniqueFiltered, 't1', function ($j) {
                $j->on(DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'));
            })
            ->whereRaw($this->getSafeEdatuForUniqueItem('t1') . " >= ? AND " . $this->getSafeEdatuForUniqueItem('t1') . " < ?", [$startWeek, $endWeekEx]);

        // Gunakan item_total_value dari t1 (hasil dedup)
        $dueThisWeekBySo = (clone $dueThisWeekBase)
            ->groupBy('t2.VBELN', 't2.BSTNK', 't2.NAME1', 't1.WAERK', 't2.IV_WERKS_PARAM', 't2.IV_AUART_PARAM')
            ->selectRaw("
            t2.VBELN, t2.BSTNK, t2.NAME1, t1.WAERK,
            t2.IV_WERKS_PARAM, t2.IV_AUART_PARAM,
            CAST(SUM(t1.item_total_value) AS DECIMAL(18,2)) AS total_value,
            DATE_FORMAT(MIN(" . $this->getSafeEdatuForUniqueItem('t1') . "), '%Y-%m-%d') AS due_date
        ")
            ->orderByDesc('total_value')
            ->get();


        /* =====================================================================
         * Customers Due This Week (agregasi dari item unik)
         * ===================================================================== */

        // Gunakan item_total_value dari t1 (hasil dedup)
        $dueThisWeekByCustomer = (clone $dueThisWeekBase)
            ->groupBy('t2.NAME1', 't1.WAERK')
            ->selectRaw("t2.NAME1, t1.WAERK, CAST(SUM(t1.item_total_value) AS DECIMAL(18,2)) AS total_value")
            ->orderByDesc('total_value')
            ->get();

        $chartData['due_this_week'] = [
            'start' => $startWeek->toDateTimeString(),
            'end_excl' => $endWeekEx->toDateTimeString(),
            'by_so' => $dueThisWeekBySo,
            'by_customer' => $dueThisWeekByCustomer,
        ];

        return $chartData;
    }
}
