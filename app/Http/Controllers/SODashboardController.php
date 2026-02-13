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
            ->whereRaw('CAST(t1a.PACKG AS DECIMAL(18,3)) > 0')
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
        $chartData = []; // ✅ pastikan terdefinisi

        $window   = (int) $request->query('window', 7);
        $location = $request->query('location'); // '2000' | '3000' | null
        $type     = $request->query('type');     // 'lokal' | 'export' | null
        $auart    = $request->query('auart');    // optional

        $today     = now()->startOfDay();
        $startWeek = now()->startOfWeek(\Carbon\Carbon::MONDAY)->startOfDay(); // inclusive
        $endWeekEx = (clone $startWeek)->addWeek(); // exclusive

        // Parser tanggal aman (alias t2)
        $safeEdatuT2 = "COALESCE(
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
        )";

        /**
         * ✅ Helper filter AUART/TYPE (versi Kode 1)
         * - jika auart dipilih: pakai auartList (WERKS-aware jika method ada)
         * - jika type dipilih:
         *    lokal  = Deskription contains local AND NOT replace
         *    export = Deskription contains export AND NOT local AND NOT replace
         * - jika type kosong & auart kosong: include semua kecuali replace (biar konsisten dengan pills di kode 1)
         * - pakai whereExists (bukan join) supaya tidak ada duplikasi row dari maping
         */
        $applyTypeOrAuart = function ($q, string $alias) use ($type, $auart, $location) {

            if (!empty($auart)) {
                $auartList = method_exists($this, 'resolveAuartListForContext')
                    ? $this->resolveAuartListForContext($auart, (string)($location ?? ''))
                    : [$auart];

                $q->whereIn("{$alias}.IV_AUART_PARAM", $auartList);
                return;
            }

            if (empty($type)) {
                $q->whereExists(function ($sub) use ($alias) {
                    $sub->selectRaw('1')
                        ->from('maping as m')
                        ->whereColumn('m.IV_WERKS', "{$alias}.IV_WERKS_PARAM")
                        ->whereColumn('m.IV_AUART', "{$alias}.IV_AUART_PARAM")
                        ->whereRaw("LOWER(COALESCE(m.Deskription,'')) NOT LIKE '%replace%'");
                });
                return;
            }

            $q->whereExists(function ($sub) use ($alias, $type) {
                $sub->selectRaw('1')
                    ->from('maping as m')
                    ->whereColumn('m.IV_WERKS', "{$alias}.IV_WERKS_PARAM")
                    ->whereColumn('m.IV_AUART', "{$alias}.IV_AUART_PARAM");

                if ($type === 'lokal') {
                    $sub->whereRaw("LOWER(COALESCE(m.Deskription,'')) LIKE '%local%'")
                        ->whereRaw("LOWER(COALESCE(m.Deskription,'')) NOT LIKE '%replace%'");
                } elseif ($type === 'export') {
                    $sub->whereRaw("LOWER(COALESCE(m.Deskription,'')) LIKE '%export%'")
                        ->whereRaw("LOWER(COALESCE(m.Deskription,'')) NOT LIKE '%local%'")
                        ->whereRaw("LOWER(COALESCE(m.Deskription,'')) NOT LIKE '%replace%'");
                    // ✅ Tidak include replace (ZRP1/ZRP2) agar sama dengan kode 1
                }
            });
        };

        /* =====================================================================
        * DEDUP ITEM UNIK (SAMA DENGAN KODE 1)
        * ===================================================================== */

        // Subquery Item Unik (VBELN, POSNR, MATNR) dari T1
        $uniqueItemsAgg = DB::table('so_yppr079_t1 as t1a')
            ->select(
                't1a.VBELN',
                't1a.KUNNR',
                't1a.IV_WERKS_PARAM',
                't1a.IV_AUART_PARAM',
                't1a.EDATU',
                't1a.WAERK',
                DB::raw('MAX(t1a.TOTPR2) AS item_total_value'),
                DB::raw('MAX(t1a.PACKG)  AS item_outs_qty')
            )
            // ✅ KODE 1: outstanding hanya yang positif
            ->whereRaw('CAST(t1a.PACKG AS DECIMAL(18,3)) > 0')
            ->groupBy(
                't1a.VBELN', 't1a.POSNR', 't1a.MATNR',
                't1a.KUNNR', 't1a.IV_WERKS_PARAM', 't1a.IV_AUART_PARAM',
                't1a.EDATU', 't1a.WAERK'
            );

        // Apply filter location + type/auart ke item unik (alias t_u)
        $itemUniqueFiltered = DB::table(DB::raw("({$uniqueItemsAgg->toSql()}) as t_u"))
            ->mergeBindings($uniqueItemsAgg)
            ->when($location, fn($q, $loc) => $q->where('t_u.IV_WERKS_PARAM', $loc));

        $applyTypeOrAuart($itemUniqueFiltered, 't_u');

        /**
         * Base join T2 untuk donut/due-this-week (boleh tetap),
         * tapi KPI total/overdue jangan dihitung dari join ini (agar sama seperti kode 1).
         */
        $allOutstandingItemsBase = DB::table('so_yppr079_t2 as t2')
            ->joinSub($itemUniqueFiltered, 't1', function ($j) {
                $j->on(DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'));
            });

        /* =====================================================================
        * KPI BLOCK BARU — ✅ HITUNG DARI ITEM UNIK (t_u), BUKAN DARI JOIN t2
        * ===================================================================== */

        $kpiBase = (clone $itemUniqueFiltered); // ✅ source KPI setara kode 1

        // 1) Outstanding Value dan Count
        $totalAgg = (clone $kpiBase)
            ->groupBy('t_u.IV_WERKS_PARAM', 't_u.WAERK')
            ->selectRaw("
                t_u.IV_WERKS_PARAM as werks,
                t_u.WAERK as cur,
                CAST(ROUND(SUM(CAST(t_u.item_total_value AS DECIMAL(18,2))), 0) AS DECIMAL(18,0)) AS value,
                COUNT(DISTINCT t_u.VBELN) AS so_count
            ")
            ->get();

        // 2) Overdue Value dan Count (EDATU < hari ini) — pakai EDATU item unik (t_u)
        $overdueAgg = (clone $kpiBase)
            ->whereRaw($this->getSafeEdatuForUniqueItem('t_u') . " < CURDATE()")
            ->groupBy('t_u.IV_WERKS_PARAM', 't_u.WAERK')
            ->selectRaw("
                t_u.IV_WERKS_PARAM as werks,
                t_u.WAERK as cur,
                CAST(ROUND(SUM(CAST(t_u.item_total_value AS DECIMAL(18,2))), 0) AS DECIMAL(18,0)) AS value,
                COUNT(DISTINCT t_u.VBELN) AS so_count
            ")
            ->get();

        $kpiNew = [];
        $buildSoCount = function (?string $forcedType = null, bool $overdue = false) use ($location, $auart) {
            $q = DB::table('so_yppr079_t2 as t2c')
                ->when($location, fn($qq, $loc) => $qq->where('t2c.IV_WERKS_PARAM', $loc))
                ->whereExists(function ($sub) use ($forcedType, $overdue, $auart, $location) {

                    $sub->selectRaw('1')
                        ->from('so_yppr079_t1 as t1_check')
                        ->whereColumn('t1_check.IV_WERKS_PARAM', 't2c.IV_WERKS_PARAM')
                        ->whereRaw("TRIM(CAST(t1_check.VBELN AS CHAR)) = TRIM(CAST(t2c.VBELN AS CHAR))")
                        // ✅ Kode 1 count pakai != 0 (bukan > 0)
                        ->whereRaw('CAST(t1_check.PACKG AS DECIMAL(18,3)) <> 0');

                    // ---- Filter AUART (WERKS-aware jika method ada) ----
                    if (!empty($auart)) {
                        $auartList = method_exists($this, 'resolveAuartListForContext')
                            ? $this->resolveAuartListForContext($auart, (string)($location ?? ''))
                            : [$auart];

                        $hasAuart  = \Illuminate\Support\Facades\Schema::hasColumn('so_yppr079_t1', 'AUART');
                        $hasAuart2 = \Illuminate\Support\Facades\Schema::hasColumn('so_yppr079_t1', 'AUART2');

                        $sub->where(function ($w) use ($auartList, $hasAuart, $hasAuart2) {
                            // fallback (kalau column AUART/AUART2 tidak ada, tetap jalan)
                            $w->whereIn('t1_check.IV_AUART_PARAM', $auartList);

                            // kalau ada kolom AUART/AUART2, ikutkan supaya match kode 1
                            if ($hasAuart) {
                                $w->orWhereIn('t1_check.AUART', $auartList);
                            }
                            if ($hasAuart2) {
                                $w->orWhereIn('t1_check.AUART2', $auartList);
                            }
                        });
                    } else {
                        // ---- Filter type export/lokal ala Kode 1 (exclude replace) ----
                        $typeToUse = $forcedType; // export|lokal|null

                        if (empty($typeToUse)) {
                            // kalau tidak ada type & tidak ada auart: exclude replace
                            $sub->whereExists(function ($m) {
                                $m->selectRaw('1')
                                    ->from('maping as mm')
                                    ->whereColumn('mm.IV_WERKS', 't1_check.IV_WERKS_PARAM')
                                    ->whereColumn('mm.IV_AUART', 't1_check.IV_AUART_PARAM')
                                    ->whereRaw("LOWER(COALESCE(mm.Deskription,'')) NOT LIKE '%replace%'");
                            });
                        } else {
                            $sub->whereExists(function ($m) use ($typeToUse) {
                                $m->selectRaw('1')
                                    ->from('maping as mm')
                                    ->whereColumn('mm.IV_WERKS', 't1_check.IV_WERKS_PARAM')
                                    ->whereColumn('mm.IV_AUART', 't1_check.IV_AUART_PARAM');

                                if ($typeToUse === 'lokal') {
                                    $m->whereRaw("LOWER(COALESCE(mm.Deskription,'')) LIKE '%local%'")
                                    ->whereRaw("LOWER(COALESCE(mm.Deskription,'')) NOT LIKE '%replace%'");
                                } elseif ($typeToUse === 'export') {
                                    $m->whereRaw("LOWER(COALESCE(mm.Deskription,'')) LIKE '%export%'")
                                    ->whereRaw("LOWER(COALESCE(mm.Deskription,'')) NOT LIKE '%local%'")
                                    ->whereRaw("LOWER(COALESCE(mm.Deskription,'')) NOT LIKE '%replace%'");
                                }
                            });
                        }
                    }

                    // ---- overdue count (opsional): overdue berdasarkan EDATU item (t1_check) ----
                    if ($overdue) {
                        $sub->whereRaw($this->getSafeEdatuForUniqueItem('t1_check') . " < CURDATE()");
                    }
                })
                ->groupBy('t2c.IV_WERKS_PARAM')
                ->selectRaw('t2c.IV_WERKS_PARAM as werks, COUNT(DISTINCT t2c.VBELN) as so_count');

            return $q->get();
        };
        $locations = ['3000' => 'smg', '2000' => 'sby'];

        $soCountExport    = ($type === 'lokal')  ? collect() : $buildSoCount('export', false);
        $soCountLocal     = ($type === 'export') ? collect() : $buildSoCount('lokal',  false);
        $soOverdueExport  = ($type === 'lokal')  ? collect() : $buildSoCount('export', true);
        $soOverdueLocal   = ($type === 'export') ? collect() : $buildSoCount('lokal',  true);

        foreach ($locations as $werksCode => $prefix) {
            if ($location && $location != $werksCode) continue;

            // VALUE (tetap dari totalAgg / overdueAgg)
            $usdTotal = $totalAgg->where('werks', $werksCode)->firstWhere('cur', 'USD');
            $idrTotal = $totalAgg->where('werks', $werksCode)->firstWhere('cur', 'IDR');

            $usdOverdue = $overdueAgg->where('werks', $werksCode)->firstWhere('cur', 'USD');
            $idrOverdue = $overdueAgg->where('werks', $werksCode)->firstWhere('cur', 'IDR');

            // ✅ QTY (ambil dari count ala kode 1)
            $expCnt = $soCountExport->firstWhere('werks', $werksCode);
            $locCnt = $soCountLocal->firstWhere('werks', $werksCode);

            $expOv  = $soOverdueExport->firstWhere('werks', $werksCode);
            $locOv  = $soOverdueLocal->firstWhere('werks', $werksCode);

            $kpiNew["{$prefix}_usd_val"] = (float) ($usdTotal->value ?? 0);
            $kpiNew["{$prefix}_idr_val"] = (float) ($idrTotal->value ?? 0);

            // Export -> USD toggle
            $kpiNew["{$prefix}_usd_qty"] = (int) ($expCnt->so_count ?? 0);

            // Local -> IDR toggle
            $kpiNew["{$prefix}_idr_qty"] = (int) ($locCnt->so_count ?? 0);

            $kpiNew["{$prefix}_usd_overdue_val"] = (float) ($usdOverdue->value ?? 0);
            $kpiNew["{$prefix}_idr_overdue_val"] = (float) ($idrOverdue->value ?? 0);

            $kpiNew["{$prefix}_usd_overdue_qty"] = (int) ($expOv->so_count ?? 0);
            $kpiNew["{$prefix}_idr_overdue_qty"] = (int) ($locOv->so_count ?? 0);
        }

        $chartData['kpi_new'] = $kpiNew;

        // Kosongkan KPI lama
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
        * Donut Aging — tetap pakai T2 JOIN item unik (t1)
        * ===================================================================== */

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
        * Due This Week — tetap dari item unik (t1) + join ke T2 untuk atribut SO
        * ===================================================================== */

        $dueThisWeekBase = DB::table('so_yppr079_t2 as t2')
            ->joinSub($itemUniqueFiltered, 't1', function ($j) {
                $j->on(DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'));
            })
            ->whereRaw(
                $this->getSafeEdatuForUniqueItem('t1') . " >= ? AND " . $this->getSafeEdatuForUniqueItem('t1') . " < ?",
                [$startWeek, $endWeekEx]
            );

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
