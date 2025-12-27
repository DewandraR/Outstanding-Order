<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Str;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{

    private int $exportTokenTtlMinutes = 15;

    private function packToToken(array $payload): string
    {
        $t = (string) \Illuminate\Support\Str::ulid();
        Cache::put("smqty:$t", [
            'uid'  => Auth::id(),
            'data' => $payload,
        ], now()->addMinutes($this->exportTokenTtlMinutes));
        return $t;
    }

    private function unpackFromToken(?string $t): array
    {
        abort_unless($t, 400, 'Missing token');
        $bag = Cache::get("smqty:$t"); // multi-use: GET (bukan pull)
        abort_if(!$bag, 410, 'Token expired or not found');
        abort_if(($bag['uid'] ?? null) !== Auth::id(), 403, 'Token owner mismatch');
        // refresh TTL supaya tombol "Download" di viewer tetap hidup
        Cache::put("smqty:$t", $bag, now()->addMinutes($this->exportTokenTtlMinutes));
        return (array) ($bag['data'] ?? []);
    }

    private function resolveLocationName(string $werks): string
    {
        return ['2000' => 'Surabaya', '3000' => 'Semarang'][$werks] ?? $werks;
    }

    private function buildFileName(string $base, string $ext): string
    {
        return sprintf('%s_%s.%s', $base, \Carbon\Carbon::now()->format('Ymd_His'), $ext);
    }
    private function getSafeEdatuForUniqueItem(string $alias): string
    {
        return "COALESCE(
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST({$alias}.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST({$alias}.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
        )";
    }

    // ====== AUART helper (IV_AUART_PARAM OR AUART2) ======
    private function applyAuartT1($query, string $alias, array $auarts)
    {
        $auarts = array_values(array_filter($auarts));
        if (empty($auarts)) return $query;

        return $query->where(function ($q) use ($alias, $auarts) {
            $q->whereIn("{$alias}.IV_AUART_PARAM", $auarts)
            ->orWhereIn("{$alias}.AUART2", $auarts);
        });
    }

    /**
     * Untuk query basis t2 (header).
     * Lolos kalau:
     * - t2.IV_AUART_PARAM IN auarts
     *   ATAU
     * - ada item t1 yang VBELN match dan (t1.IV_AUART_PARAM IN auarts OR t1.AUART2 IN auarts)
     */
    private function applyAuartT2($query, array $auarts, ?string $werks = null)
    {
        $auarts = array_values(array_filter($auarts));
        if (empty($auarts)) return $query;

        return $query->where(function ($q) use ($auarts) {
            $q->whereIn('t2.IV_AUART_PARAM', $auarts)
            ->orWhereExists(function ($ex) use ($auarts) {
                $ex->select(DB::raw(1))
                    ->from('so_yppr079_t1 as t1x')
                    ->whereColumn('t1x.VBELN', 't2.VBELN')
                    // ❌ HAPUS SEMUA FILTER WERKS DI SINI
                    ->where(function ($w) use ($auarts) {
                        $w->whereIn('t1x.IV_AUART_PARAM', $auarts)
                        ->orWhereIn('t1x.AUART2', $auarts);
                    });
            });
        });
    }



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

            $this->applyAuartT2($baseQuery, $auartToQuery, $location);
        }
        $baseQuery->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc));

        // =========================================================================
        // DEDUPLIKASI ITEM (VBELN, POSNR, MATNR)
        // =========================================================================

        $poAuartList = ($type === 'export') ? $auartToQuery : []; // Gunakan AUART yang difilter
        if ($type === 'lokal') {
            // Query untuk AUART Lokal (perlu join ke maping)
            $poAuartList = $rawMapping->where('Deskription', 'like', '%Local%')->pluck('IV_AUART')->unique()->toArray();
        } elseif (!$type) {
            // Jika All Type, masukkan semua AUART
            $poAuartList = $rawMapping->pluck('IV_AUART')->unique()->toArray();
        }

        // 1. Subquery Item Unik (VBELN, POSNR, MATNR)
        $uniqueItemsAgg = DB::table('so_yppr079_t1 as t1a')
            ->join('so_yppr079_t2 as t2h', function ($j) {
                $j->on(DB::raw('TRIM(CAST(t2h.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t1a.VBELN AS CHAR))'));
            })
            ->select(
                't1a.VBELN',
                't1a.POSNR',
                't1a.MATNR',
                't1a.EDATU',
                't1a.WAERK',
                DB::raw('t2h.IV_WERKS_PARAM AS IV_WERKS_PARAM'),   // ✅ WERKS ikut header
                DB::raw('MAX(t1a.TOTPR) AS item_total_value'),
                DB::raw('MAX(t1a.QTY_BALANCE2) AS item_outs_qty')
            )
            ->whereRaw('CAST(t1a.QTY_BALANCE2 AS DECIMAL(18,3)) > 0')
            ->when($location, fn($q, $loc) => $q->where('t2h.IV_WERKS_PARAM', $loc)) // ✅
            ->when(!empty($poAuartList), fn($q) => $this->applyAuartT1($q, 't1a', $poAuartList))
            ->groupBy('t1a.VBELN','t1a.POSNR','t1a.MATNR','t1a.EDATU','t1a.WAERK', DB::raw('t2h.IV_WERKS_PARAM'));


        // 2. Query untuk NILAI (Outstanding Value) - HANYA PO yang memiliki Outstanding Qty (DARI ITEM UNIK)
        $kpiValueQuery = DB::table(DB::raw("({$uniqueItemsAgg->toSql()}) as t1_u"))->mergeBindings($uniqueItemsAgg)
            ->select(
                't1_u.IV_WERKS_PARAM as werks',
                't1_u.WAERK as currency',
                DB::raw('CAST(SUM(t1_u.item_total_value) AS DECIMAL(18,2)) as total_value'),
                // Hitung Overdue Value dari item unik
                DB::raw("CAST(SUM(CASE WHEN " . $this->getSafeEdatuForUniqueItem('t1_u') . " < CURDATE() THEN t1_u.item_total_value ELSE 0 END) AS DECIMAL(18,2)) as overdue_value")
            )
            ->groupBy('t1_u.IV_WERKS_PARAM', 't1_u.WAERK')
            ->get();


        // 3. Query untuk JUMLAH PO/SO (QTY PO Count) - Menggunakan $baseQuery T2 JOIN T1 (tanpa filter quantity)
        $kpiQtyQuery = (clone $baseQuery)
            ->selectRaw("
                t2.IV_WERKS_PARAM as werks,
                t2.WAERK as currency,
                COUNT(DISTINCT t2.VBELN) as total_qty, 
                COUNT(DISTINCT CASE WHEN {$safeEdatu} < CURDATE() THEN t2.VBELN ELSE NULL END) as overdue_qty
            ")
            // Join ke t1 untuk memastikan PO memiliki item
            ->join('so_yppr079_t1 as t1', function ($j) {
                $j->on(DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'));
            })
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

        // Isi data KPI NILAI (Outstanding Value & Overdue Value) DARI ITEM UNIK
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

            // Total Outstanding Qty (Menggunakan total PO/SO dari T2)
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
        // MODIFIKASI: QUERY GRAFIK YANG DIBAGI PER LOKASI (Menggunakan Item Unik)
        // =========================================================================

        $locationQueries = [
            'smg' => (clone $baseQuery)->where('t2.IV_WERKS_PARAM', '3000'),
            'sby' => (clone $baseQuery)->where('t2.IV_WERKS_PARAM', '2000'),
        ];

        // Gabungkan item unik dengan T2
        $itemUniqueWithHeader = DB::table('so_yppr079_t2 as t2')
            ->joinSub($uniqueItemsAgg, 't1_u', function ($j) {
                $j->on(DB::raw('TRIM(CAST(t1_u.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'));
            });


        // 1. Top customers (USD) - PER LOKASI (DEDUPLIKASI)
        foreach (['smg', 'sby'] as $prefix) {
            $query = (clone $itemUniqueWithHeader)->where('t2.IV_WERKS_PARAM', $prefix === 'smg' ? '3000' : '2000');
            $chartData["top_customers_value_usd_{$prefix}"] = $query
                ->select(
                    't2.NAME1',
                    DB::raw('SUM(t1_u.item_total_value) as total_value'),
                    DB::raw('COUNT(DISTINCT t2.VBELN) as so_count')
                )
                ->where('t1_u.WAERK', 'USD')
                ->groupBy('t2.NAME1')
                ->having('total_value', '>', 0)
                ->orderByDesc('total_value')
                ->limit(4)
                ->get();
        }

        // 2. Top customers (IDR) - PER LOKASI (DEDUPLIKASI)
        foreach (['smg', 'sby'] as $prefix) {
            $query = (clone $itemUniqueWithHeader)->where('t2.IV_WERKS_PARAM', $prefix === 'smg' ? '3000' : '2000');
            $chartData["top_customers_value_idr_{$prefix}"] = $query
                ->select(
                    't2.NAME1',
                    DB::raw('SUM(t1_u.item_total_value) as total_value'),
                    DB::raw('COUNT(DISTINCT t2.VBELN) as so_count')
                )
                ->where('t1_u.WAERK', 'IDR')
                ->groupBy('t2.NAME1')
                ->having('total_value', '>', 0)
                ->orderByDesc('total_value')
                ->limit(4)
                ->get();
        }

        // 3. Top customers overdue - PER LOKASI (Count PO yang overdue)
        foreach ($locationQueries as $prefix => $query) {
            $chartData["top_customers_overdue_{$prefix}"] = (clone $query)
                // Join ke t1_check (item) untuk memastikan hanya SO yang memiliki outstanding item dihitung
                ->join('so_yppr079_t1 as t1_check', function ($j) {
                    $j->on(DB::raw('TRIM(CAST(t1_check.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'))
                        ->whereRaw('CAST(t1_check.QTY_BALANCE2 AS DECIMAL(18,3)) > 0');
                })
                ->select(
                    't2.NAME1',
                    DB::raw('COUNT(DISTINCT t2.VBELN) as overdue_count'),
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
            // Join ke item unik (t1_u) untuk agregasi nilai
            ->joinSub($uniqueItemsAgg, 't1_u', function ($j) {
                $j->on(DB::raw('TRIM(CAST(t1_u.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'));
            });

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

        $safeEdatuPerf = "
            COALESCE(
                STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y'),
                STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d')
            )";

        $performanceQuery = $performanceQueryBase->select(
            'm.Deskription',
            'm.IV_WERKS',
            'm.IV_AUART',
            DB::raw('COUNT(DISTINCT t2.VBELN) as total_so'),
            // Gunakan SUM(t1_u.item_total_value) dari item unik
            DB::raw("SUM(CASE WHEN t2.WAERK = 'IDR' AND {$safeEdatuPerf} < CURDATE() THEN CAST(t1_u.item_total_value AS DECIMAL(18,2)) ELSE 0 END) as total_value_idr"),
            DB::raw("SUM(CASE WHEN t2.WAERK = 'USD' AND {$safeEdatuPerf} < CURDATE() THEN CAST(t1_u.item_total_value AS DECIMAL(18,2)) ELSE 0 END) as total_value_usd"),
            DB::raw("COUNT(DISTINCT CASE WHEN {$safeEdatuPerf} < CURDATE() THEN t2.VBELN ELSE NULL END) as overdue_so_count"),
            DB::raw("COUNT(DISTINCT CASE WHEN DATEDIFF(CURDATE(), {$safeEdatuPerf}) BETWEEN 1 AND 30 THEN t2.VBELN ELSE NULL END) as overdue_1_30"),
            DB::raw("COUNT(DISTINCT CASE WHEN DATEDIFF(CURDATE(), {$safeEdatuPerf}) BETWEEN 31 AND 60 THEN t2.VBELN ELSE NULL END) as overdue_31_60"),
            DB::raw("COUNT(DISTINCT CASE WHEN DATEDIFF(CURDATE(), {$safeEdatuPerf}) BETWEEN 61 AND 90 THEN t2.VBELN ELSE NULL END) as overdue_61_90"),
            DB::raw("COUNT(DISTINCT CASE WHEN DATEDIFF(CURDATE(), {$safeEdatuPerf}) > 90 THEN t2.VBELN ELSE NULL END) as overdue_over_90")
        )
            ->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc))
            ->groupBy('m.IV_WERKS', 'm.IV_AUART', 'm.Deskription')
            ->orderBy('m.IV_WERKS')->orderBy('m.Deskription')
            ->get();

        $chartData['so_performance_analysis'] = $performanceQuery;

        // Small qty by customer - TETAP (sudah menggunakan QTY_BALANCE2)
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
            'werks'  => 'required|string',
            'auart'  => 'required|string',
            'bucket' => 'required|string|in:on_track,1_30,31_60,61_90,gt_90',
            'kunnr'  => 'nullable|string',
        ]);

        $werks  = $request->query('werks');
        $auart  = $request->query('auart');
        $bucket = $request->query('bucket');
        $kunnr  = $request->query('kunnr');

        // --- Logika Export + Replace ---
        $mapping = DB::table('maping')->get();

        $exportAuartCodes = $mapping->filter(function ($item) {
            $d = strtolower((string)$item->Deskription);
            return Str::contains($d, 'export')
                && !Str::contains($d, 'local')
                && !Str::contains($d, 'replace');
        })->pluck('IV_AUART')->unique()->toArray();

        $replaceAuartCodes = $mapping->filter(function ($item) {
            return Str::contains(strtolower((string)$item->Deskription), 'replace');
        })->pluck('IV_AUART')->unique()->toArray();

        $auartList = [$auart];
        if (in_array($auart, $exportAuartCodes, true) && !in_array($auart, $replaceAuartCodes, true)) {
            $auartList = array_merge($exportAuartCodes, $replaceAuartCodes);
        }
        $auartList = array_values(array_unique(array_filter($auartList)));
        // --- End ---

        $safeEdatu = "COALESCE(
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
        )";

        $q = DB::table('so_yppr079_t2 as t2')
            ->selectRaw("
                TRIM(t2.BSTNK) AS PO,
                TRIM(t2.VBELN) AS SO,
                DATE_FORMAT({$safeEdatu}, '%d-%m-%Y') AS EDATU,
                DATEDIFF(CURDATE(), {$safeEdatu}) AS OVERDUE_DAYS,
                MAX(t2.NAME1) AS CUSTOMER_NAME_MODAL
            ")
            ->where('t2.IV_WERKS_PARAM', $werks)
            ->when($kunnr, fn($qq) => $qq->where('t2.KUNNR', $kunnr));

        // ✅ Filter AUART yang benar (header bisa nyebrang via AUART2)
        $q = $this->applyAuartT2($q, $auartList, $werks);

        // ✅ hanya yang punya item outstanding dalam konteks AUART (IV_AUART atau AUART2)
        $q->whereExists(function ($ex) use ($auartList, $werks) {
            $ex->select(DB::raw(1))
                ->from('so_yppr079_t1 as t1_check')
                ->whereColumn('t1_check.VBELN', 't2.VBELN')
                ->where(function ($w) use ($auartList) {
                    $w->whereIn('t1_check.IV_AUART_PARAM', $auartList)
                    ->orWhereIn('t1_check.AUART2', $auartList);
                })
                ->whereRaw('CAST(t1_check.QTY_BALANCE2 AS DECIMAL(18,3)) > 0');
        });

        // bucket filter
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
            ->groupBy('t2.BSTNK', 't2.VBELN', 't2.EDATU')
            ->orderByRaw("CASE WHEN {$safeEdatu} >= CURDATE() THEN 0 ELSE 1 END")
            ->orderByRaw("CASE WHEN {$safeEdatu} >= CURDATE() THEN {$safeEdatu} ELSE NULL END ASC")
            ->orderByDesc('OVERDUE_DAYS')
            ->orderBy('t2.VBELN')
            ->get();

        return response()->json(['ok' => true, 'data' => $rows]);
    }


    public function apiT2(Request $req)
    {
        $kunnr = (string) $req->query('kunnr');
        $werks = $req->query('werks');
        $auart = $req->query('auart');

        if ($kunnr === '') {
            return response()->json(['ok' => false, 'error' => 'kunnr missing'], 400);
        }

        // ===== Logika gabung Export + Replace (konsisten dgn yang lain) =====
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

        // Jika konteks AUART = Export asli, gabungkan dengan Replace
        $auartList = $auart ? [$auart] : [];
        if ($auart && in_array($auart, $exportAuartCodes) && !in_array($auart, $replaceAuartCodes)) {
            $auartList = array_unique(array_merge($exportAuartCodes, $replaceAuartCodes));
        }

        // ====== PRE-AGREGASI (cepat) ======
        // Total nilai & outstanding qty per SO
        $uniqueItemsAgg = DB::table('so_yppr079_t1 as t1a')
            ->select(
                't1a.VBELN',
                't1a.POSNR',
                't1a.MATNR',
                't1a.WAERK',
                't1a.IV_WERKS_PARAM',
                DB::raw('MAX(t1a.TOTPR) AS item_total_value'),
                DB::raw('MAX(t1a.QTY_BALANCE2) AS item_outs_qty')
            )
            ->whereRaw('CAST(t1a.QTY_BALANCE2 AS DECIMAL(18,3)) > 0') // hanya outstanding
            ->when(!empty($auartList), fn($q) => $this->applyAuartT1($q, 't1a', $auartList))
            ->groupBy('t1a.VBELN', 't1a.POSNR', 't1a.MATNR', 't1a.WAERK', 't1a.IV_WERKS_PARAM');

        // Total per SO dari item unik (bukan dari baris dupe)
        $aggTotals = DB::table(DB::raw("({$uniqueItemsAgg->toSql()}) as t1_u"))
            ->mergeBindings($uniqueItemsAgg)
            ->groupBy('t1_u.VBELN')
            ->select(
                't1_u.VBELN',
                DB::raw('CAST(ROUND(SUM(t1_u.item_total_value), 0) AS DECIMAL(18,0)) AS total_value'),
                DB::raw('CAST(SUM(t1_u.item_outs_qty) AS DECIMAL(18,3)) AS outs_qty')
            );

        // Jumlah remark per SO (untuk badge biru)
        $remarksBySo = DB::table('item_remarks as ir')
            ->when($werks, fn($q) => $q->where('ir.IV_WERKS_PARAM', $werks))
            ->when(!empty($auartList), fn($q) => $q->whereIn('ir.IV_AUART_PARAM', $auartList))
            ->whereNotNull('ir.remark')->whereRaw('TRIM(ir.remark) <> ""')
            ->groupBy('ir.VBELN')
            ->select('ir.VBELN', DB::raw('COUNT(*) AS po_remark_count'));

        // ====== QUERY UTAMA ======
        $rows = DB::table('so_yppr079_t2 as t2')
            ->leftJoinSub($aggTotals, 'ag', fn($j) => $j->on('ag.VBELN', '=', 't2.VBELN'))
            ->leftJoinSub($remarksBySo, 'rk', fn($j) => $j->on('rk.VBELN', '=', 't2.VBELN'))
            ->when($werks, fn($q) => $q->where('t2.IV_WERKS_PARAM', $werks))
            ->when(!empty($auartList), fn($q) => $this->applyAuartT2($q, $auartList, $werks))
            // toleransi format KUNNR agar kompatibel dgn data lama
            ->where(function ($q) use ($kunnr) {
                $q->where('t2.KUNNR', $kunnr)
                    ->orWhereRaw('TRIM(CAST(t2.KUNNR AS CHAR)) = TRIM(?)', [$kunnr])
                    ->orWhereRaw('CAST(TRIM(t2.KUNNR) AS UNSIGNED) = CAST(TRIM(?) AS UNSIGNED)', [$kunnr]);
            })
            ->select(
                't2.VBELN',
                't2.BSTNK',
                't2.WAERK',
                't2.EDATU',
                DB::raw('COALESCE(ag.total_value, 0)  AS total_value'),
                DB::raw('COALESCE(ag.outs_qty, 0)     AS outs_qty'),
                DB::raw('COALESCE(rk.po_remark_count, 0) AS po_remark_count')
            )
            ->get();

        // ====== Hitung Overdue & format tanggal (di PHP, cepat) ======
        $today = now()->startOfDay();
        foreach ($rows as $row) {
            $row->Overdue = 0;
            $row->FormattedEdatu = '';

            if (!empty($row->EDATU) && $row->EDATU !== '0000-00-00') {
                try {
                    $edatu = \DateTime::createFromFormat('Y-m-d', $row->EDATU)
                        ?: \DateTime::createFromFormat('d-m-Y', $row->EDATU);

                    if ($edatu) {
                        $row->FormattedEdatu = $edatu->format('d-m-Y');
                        $edatu->setTime(0, 0, 0);

                        // positif = sudah lewat (overdue), negatif = masih sisa hari
                        $diff = $today->diff(Carbon::instance($edatu));
                        $row->Overdue = $diff->invert ? (int)$diff->days : -(int)$diff->days;
                    }
                } catch (\Throwable $e) {
                    $row->Overdue = 0;
                }
            }
        }

        // Urutkan: overdue (+) diletakkan paling atas, lalu yang paling dekat
        $sorted = collect($rows)->sort(function ($a, $b) {
            $aOver = $a->Overdue > 0;
            $bOver = $b->Overdue > 0;
            if ($aOver !== $bOver) return $aOver ? -1 : 1;
            return $b->Overdue <=> $a->Overdue; // dalam grup: terbesar dulu
        })->values();

        return response()->json(['ok' => true, 'data' => $sorted]);
    }

    public function apiT3(Request $req)
    {
        $vbeln = trim((string) $req->query('vbeln'));
        if ($vbeln === '') {
            return response()->json(['ok' => false, 'error' => 'vbeln missing'], 400);
        }

        $werks = $req->query('werks');

        // ✅ sanitasi auart untuk dipakai sebagai AUART_KEY konstan
        $auartRaw = strtoupper(trim((string) $req->query('auart', '')));
        $auartSan = preg_replace('/[^A-Z0-9_]/', '', $auartRaw);

        // === gabungkan Export + Replace bila perlu ===
        $mappingQ = DB::table('maping');
        if (!empty($werks)) {
            $mappingQ->where('IV_WERKS', $werks); // ✅ batasi per plant biar ZOR1 nggak nyasar ke 3000
        }
        $mapping = $mappingQ->get();

        $exportAuartCodes = $mapping->filter(function ($item) {
            $d = strtolower((string)$item->Deskription);
            return Str::contains($d, 'export') && !Str::contains($d, 'local') && !Str::contains($d, 'replace');
        })->pluck('IV_AUART')->unique()->toArray();

        $replaceAuartCodes = $mapping->filter(function ($item) {
            return Str::contains(strtolower((string)$item->Deskription), 'replace');
        })->pluck('IV_AUART')->unique()->toArray();

        $auartList = [];
        if ($auartSan !== '') {
            $auartList = [$auartSan];

            // ✅ kalau AUART export (di plant tsb), gabungkan dengan replace plant tsb saja
            if (in_array($auartSan, $exportAuartCodes, true) && !in_array($auartSan, $replaceAuartCodes, true)) {
                $auartList = array_values(array_unique(array_merge([$auartSan], $replaceAuartCodes)));
            }

            // opsional: kalau user klik replace, gabungkan replace + export di plant tsb (kalau memang mau)
            // if (in_array($auartSan, $replaceAuartCodes, true)) {
            //     $auartList = array_values(array_unique(array_merge($exportAuartCodes, $replaceAuartCodes)));
            // }
        }


        // ✅ AUART_KEY dipaksa = AUART request (bukan t1.IV_AUART_PARAM)
        $auartKeySelect = $auartSan !== ''
            ? DB::raw("'" . $auartSan . "' as AUART_KEY")
            : DB::raw('t1.IV_AUART_PARAM as AUART_KEY');

       $auartKeysForSo = DB::query()
        ->fromSub(function ($u) use ($vbeln) {
            $u->from('so_yppr079_t1 as x')
            ->select(DB::raw('TRIM(x.IV_AUART_PARAM) as AUART'))
            ->whereRaw('TRIM(CAST(x.VBELN AS CHAR)) = TRIM(?)', [$vbeln])
            ->whereNotNull('x.IV_AUART_PARAM')->whereRaw("TRIM(x.IV_AUART_PARAM) <> ''")
            ->unionAll(
                DB::table('so_yppr079_t1 as y')
                    ->select(DB::raw('TRIM(y.AUART2) as AUART'))
                    ->whereRaw('TRIM(CAST(y.VBELN AS CHAR)) = TRIM(?)', [$vbeln])
                    ->whereNotNull('y.AUART2')->whereRaw("TRIM(y.AUART2) <> ''")
            );
        }, 'uu')
        ->select('uu.AUART');

    // remarksAgg: NO WERKS filter, AUART pakai AUART+AUART2 dari SO tsb
    $remarksAgg = DB::table('item_remarks as ir')
        ->select(
            DB::raw('TRIM(CAST(ir.VBELN AS CHAR)) as VBELN'),
            DB::raw("LPAD(TRIM(CAST(ir.POSNR AS CHAR)), 6, '0') as POSNR_DB"),
            DB::raw('COUNT(*) as remark_count'),
            DB::raw('MAX(ir.created_at) as last_remark_at')
        )
        ->whereIn(DB::raw('TRIM(ir.IV_AUART_PARAM)'), $auartKeysForSo)
        ->whereRaw("TRIM(COALESCE(ir.remark,'')) <> ''")
        ->groupBy(
            DB::raw('TRIM(CAST(ir.VBELN AS CHAR))'),
            DB::raw("LPAD(TRIM(CAST(ir.POSNR AS CHAR)), 6, '0')")
        );

        $rows = DB::table('so_yppr079_t1 as t1')
            ->leftJoin(
                'so_yppr079_t2 as t2',
                DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'),
                '=',
                DB::raw('TRIM(CAST(t1.VBELN AS CHAR))')
            )
            ->leftJoinSub($remarksAgg, 'ragg', function ($j) {
                $j->on(DB::raw('TRIM(CAST(ragg.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'))
                ->on('ragg.POSNR_DB', '=', DB::raw("LPAD(TRIM(CAST(t1.POSNR AS CHAR)), 6, '0')"));
            })
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
                't2.IV_WERKS_PARAM as WERKS_KEY',
                $auartKeySelect,
                DB::raw("LPAD(TRIM(t1.POSNR), 6, '0') as POSNR_DB"),
                DB::raw('COALESCE(ragg.remark_count, 0) as remark_count'),
                DB::raw('ragg.last_remark_at as last_remark_at')
            )
            ->whereRaw('TRIM(CAST(t1.VBELN AS CHAR)) = TRIM(?)', [$vbeln])
            ->when($werks, fn($q) => $q->where('t2.IV_WERKS_PARAM', $werks))
            ->when(!empty($auartList), fn($q) => $this->applyAuartT1($q, 't1', $auartList))
            ->orderByRaw('CAST(t1.POSNR AS UNSIGNED)')
            ->get();

        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function search(Request $request)
    {
        // 1) Validasi input
        $request->validate([
            'term'   => 'required|string|max:100',
            'target' => 'nullable|in:po,so,stock',
        ]);

        $term   = trim((string) $request->input('term'));
        $target = $request->input('target');

        // Fallback kalau hidden "target" tak terkirim: deteksi dari referer
        if (!$target) {
            $ref = (string) $request->headers->get('referer', '');
            if (\Illuminate\Support\Str::contains($ref, ['/so-dashboard'])) {
                $target = 'so';
            } elseif (\Illuminate\Support\Str::contains($ref, ['/stock-dashboard'])) {
                $target = 'stock';
            } else {
                $target = 'po';
            }
        }

        // 2) Cari di T2 (SO/PO) – cocokkan ke VBELN atau BSTNK
        $soInfo = DB::table('so_yppr079_t2')
            ->where(function ($q) use ($term) {
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
        $auart = trim((string) $soInfo->IV_AUART_PARAM);

        /*
     |------------------------------------------
     | Cabang: SO Report
     | route name: so.index  (/outstanding-so)
     |------------------------------------------
     */
        if ($target === 'so') {
            $payload = [
                'view'            => 'so',
                'werks'           => $werks,
                'auart'           => $auart,   // <-- tambahkan ini
                'auto'            => 1,
                'highlight_kunnr' => $soInfo->KUNNR,
                'highlight_vbeln' => $soInfo->VBELN,
                'search_term'     => $term,
            ];

            $q = Crypt::encrypt($payload);
            return redirect()->route('so.index', ['q' => $q]);
        }

        /*
     |------------------------------------------
     | Cabang: Stock Report
     | route name: stock.index  (/stock-report)
     |------------------------------------------
     */
        if ($target === 'stock') {
            $payload = [
                'view'             => 'stock',
                'werks'            => $werks,
                'auto_expand'      => 1,                 // untuk konsistensi dengan pola "expand"
                'highlight_kunnr'  => $soInfo->KUNNR,
                'highlight_vbeln'  => $soInfo->VBELN,
                'highlight_bstnk'  => $soInfo->BSTNK,
                'search_term'      => $term,
            ];

            $q = Crypt::encrypt($payload);
            return redirect()->route('stock.index', ['q' => $q]);
        }

        /*
     |------------------------------------------
     | Default: PO Report (existing behaviour)
     | route name: po.report  (/po-report)
     | Termasuk logika mapping ZRP -> Export AUART
     |------------------------------------------
     */
        $auartForReport = $auart;
        if (strtoupper($auart) === 'ZRP') {
            $exportAuart = DB::table('maping')
                ->where('IV_WERKS', $werks)
                ->whereRaw("LOWER(Deskription) LIKE '%export%'")
                ->whereRaw("LOWER(Deskription) NOT LIKE '%local%'")
                ->whereRaw("LOWER(Deskription) NOT LIKE '%replace%'")
                ->value('IV_AUART'); // ambil satu (yang utama) untuk plant tsb

            if (!empty($exportAuart)) {
                $auartForReport = trim((string) $exportAuart);
            }
        }

        $params = [
            'view'             => 'po',
            'werks'            => $werks,
            'auart'            => $auartForReport,
            'compact'          => 1,
            'auto_expand'      => 1,                  // sinyal buka T2
            'highlight_kunnr'  => $soInfo->KUNNR,
            'highlight_vbeln'  => $soInfo->VBELN,
            'highlight_bstnk'  => $soInfo->BSTNK,
            'search_term'      => $term,
            'auto'             => 1,                  // kompatibilitas lama jika ada JS baca "auto"
        ];

        $encrypted = Crypt::encrypt($params);

        return redirect()->route('po.report', [
            'q'               => $encrypted,
            'auto_expand'     => 1,
            'highlight_kunnr' => $soInfo->KUNNR,
            'highlight_vbeln' => $soInfo->VBELN,
            'highlight_bstnk' => $soInfo->BSTNK,
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
            $q->where(function ($w) use ($auartList) {
                $w->whereIn('t2.IV_AUART_PARAM', $auartList)
                ->orWhereIn('t1.AUART2', $auartList);
            });
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
        $query->where(function ($w) use ($auartList) {
            $w->whereIn('t2.IV_AUART_PARAM', $auartList)
            ->orWhereIn('t1.AUART2', $auartList);
        });

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
            'status'   => 'required|string|in:overdue,due_this_week,on_time',
            'location' => 'nullable|string|in:2000,3000',
            'type'     => 'nullable|string|in:lokal,export',
        ]);

        $status   = $request->query('status');
        $location = $request->query('location');
        $type     = $request->query('type');

        // --- LOGIKA PENGGABUNGAN ---
        $mapping = DB::table('maping')->get();

        $exportAuartCodes = $mapping->filter(function ($item) {
            $d = strtolower((string)$item->Deskription);
            return Str::contains($d, 'export')
                && !Str::contains($d, 'local')
                && !Str::contains($d, 'replace');
        })->pluck('IV_AUART')->unique()->toArray();

        $replaceAuartCodes = $mapping->filter(function ($item) {
            return Str::contains(strtolower((string)$item->Deskription), 'replace');
        })->pluck('IV_AUART')->unique()->toArray();
        // --- END LOGIKA PENGGABUNGAN ---

        $safeEdatu = "
            COALESCE(
                STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y'),
                STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d')
            )
        ";

        $base = DB::table('so_yppr079_t2 as t2')
            ->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc))
            ->join(
                'so_yppr079_t1 as t1',
                DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                '=',
                DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
            )
            // hanya outstanding
            ->whereRaw('CAST(t1.QTY_BALANCE2 AS DECIMAL(18,3)) > 0');

        // type filter
        if ($type === 'lokal') {
            // Lokal tetap pakai rule lama (mapping lokal)
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
            // Export harus include AUART2
            $auartToQuery = array_values(array_unique(array_merge($exportAuartCodes, $replaceAuartCodes)));

            $base->where(function ($w) use ($auartToQuery) {
                $w->whereIn('t2.IV_AUART_PARAM', $auartToQuery)
                ->orWhereIn('t1.AUART2', $auartToQuery);
            });
        }

        // status filter
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
        return $this->exportSmallQtyStart($request);
    }


    public function exportSmallQtyStart(Request $request)
    {
        $validated = $request->validate([
            'customerName' => 'required|string',
            'locationName' => 'required|string|in:Semarang,Surabaya',
            'type'         => 'nullable|string',
            'auart'        => 'nullable|string',
        ]);

        $payload = [
            'customerName' => $validated['customerName'],
            'locationName' => $validated['locationName'],
            'type'         => $validated['type'] ?? null,
            'auart'        => $validated['auart'] ?? null,
        ];

        // Token cache (multi-use)
        $t = $this->packToToken($payload);

        // Redirect 303 ke GET streamer (pakai t)
        return redirect()->route('dashboard.export.smallQtyPdf.show', ['t' => $t], 303);
    }
    // GET -> bangun PDF & stream inline (viewer bisa Download)
    public function exportSmallQtyShow(Request $request)
    {
        // 1) Ambil payload
        if ($request->filled('t')) {
            $p = $this->unpackFromToken($request->query('t'));
        } else {
            // Fallback legacy "q"
            if (!$request->filled('q')) abort(400, 'Missing token.');
            try {
                $p = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($request->query('q')), true) ?: [];
            } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                abort(400, 'Invalid token.');
            }
        }

        $customerName = (string)($p['customerName'] ?? '');
        $locationName = (string)($p['locationName'] ?? '');
        $type         = $p['type'] ?? null;
        $auartReq     = $p['auart'] ?? null;

        if ($customerName === '' || !in_array($locationName, ['Semarang', 'Surabaya'], true)) {
            abort(422, 'Bad parameters.');
        }

        $werks = $locationName === 'Semarang' ? '3000' : '2000';

        // 2) Logika gabung Export + Replace (konsisten)
        $mapping = DB::table('maping')->get();
        $exportAuartCodes = $mapping->filter(function ($i) {
            $d = strtolower($i->Deskription);
            return str_contains($d, 'export') && !str_contains($d, 'local') && !str_contains($d, 'replace');
        })->pluck('IV_AUART')->unique()->toArray();

        $replaceAuartCodes = $mapping->filter(fn($i) => str_contains(strtolower($i->Deskription), 'replace'))
            ->pluck('IV_AUART')->unique()->toArray();

        $auartList = array_filter([(string)$auartReq]);
        if (!empty($auartList) && in_array($auartList[0], $exportAuartCodes, true)) {
            $auartList = array_unique(array_merge($exportAuartCodes, $replaceAuartCodes));
        }

        // 3) Query data
        $q = DB::table('so_yppr079_t1 as t1')
            ->join(
                'so_yppr079_t2 as t2',
                DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                '=',
                DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
            )
            ->leftJoin('maping as m', function ($j) {
                $j->on('t2.IV_AUART_PARAM', '=', 'm.IV_AUART')
                    ->on('t2.IV_WERKS_PARAM', '=', 'm.IV_WERKS');
            })
            ->where('t2.NAME1', $customerName)
            ->where('t2.IV_WERKS_PARAM', $werks)
            ->whereRaw('CAST(t1.QTY_BALANCE2 AS DECIMAL(18,3)) > 0')
            ->whereRaw('CAST(t1.QTY_BALANCE2 AS DECIMAL(18,3)) <= 5')
            ->whereRaw('CAST(t1.QTY_GI AS DECIMAL(18,3)) > 0');

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
            't1.KALAB',
            't1.KALAB2'
        )
            ->orderBy('t2.VBELN')
            ->orderByRaw('LPAD(TRIM(CAST(t1.POSNR AS CHAR)), 6, "0")')
            ->get();

        // 4) Render PDF (binary) + header aman
        $meta = [
            'customerName' => $customerName,
            'locationName' => $locationName,
            'type'         => $type,
            'generatedAt'  => now()->format('d-m-Y'),
        ];

        $pdfBinary = Pdf::loadView('po_report.small-qty-pdf', [
            'items'  => $items,
            'meta'   => $meta,
            'totals' => [
                'total_item' => $items->count(),
                'total_po'   => $items->pluck('PO')->filter()->unique()->count(),
            ],
        ])
            ->setPaper('a4', 'portrait')
            ->output();

        // Nama file konsisten + aman di semua browser
        $fileBase = 'SmallQty_' . $locationName . '_' . \Illuminate\Support\Str::slug($customerName);
        $fileName = $this->buildFileName($fileBase, 'pdf');
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response()->stream(function () use ($pdfBinary) {
            echo $pdfBinary;
        }, 200, [
            'Content-Type'           => 'application/pdf',
            'Content-Disposition'    => $disposition . '; filename="' . $fileName . '"; filename*=UTF-8\'\'' . rawurlencode($fileName),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, max-age=60, must-revalidate',
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

        // Mapping untuk export+replace
        $mapping = DB::table('maping')->get();

        $exportAuartCodes = $mapping->filter(function ($item) {
            $d = strtolower((string)$item->Deskription);
            return Str::contains($d, 'export')
                && !Str::contains($d, 'local')
                && !Str::contains($d, 'replace');
        })->pluck('IV_AUART')->unique()->toArray();

        $replaceAuartCodes = $mapping->filter(function ($item) {
            return Str::contains(strtolower((string)$item->Deskription), 'replace');
        })->pluck('IV_AUART')->unique()->toArray();

        $exportPlusReplace = array_values(array_unique(array_merge($exportAuartCodes, $replaceAuartCodes)));

        // ============================================================
        // 1) DEDUP REMARKS:
        //    1 baris per (VBELN, POSNR, WERKS, AUART, REMARK, CREATED_AT)
        // ============================================================
        $remarksDedup = DB::table('item_remarks as ir')
            ->whereRaw("TRIM(COALESCE(ir.remark,'')) <> ''")
            ->selectRaw("
                MIN(ir.id) as id,
                TRIM(CAST(ir.VBELN AS CHAR)) as VBELN_KEY,
                LPAD(TRIM(CAST(ir.POSNR AS CHAR)), 6, '0') as POSNR_KEY,
                TRIM(CAST(ir.IV_WERKS_PARAM AS CHAR)) as WERKS_KEY,
                TRIM(CAST(ir.IV_AUART_PARAM AS CHAR)) as AUART_KEY,
                TRIM(ir.remark) as remark,
                ir.created_at as created_at,
                MAX(ir.updated_at) as updated_at
            ")
            ->groupBy(
                DB::raw("TRIM(CAST(ir.VBELN AS CHAR))"),
                DB::raw("LPAD(TRIM(CAST(ir.POSNR AS CHAR)), 6, '0')"),
                DB::raw("TRIM(CAST(ir.IV_WERKS_PARAM AS CHAR))"),
                DB::raw("TRIM(CAST(ir.IV_AUART_PARAM AS CHAR))"),
                DB::raw("TRIM(ir.remark)"),
                'ir.created_at'
            );

        // ============================================================
        // 2) DEDUP T1: 1 baris per (VBELN, POSNR) untuk data item
        // ============================================================
        $t1Dedup = DB::table('so_yppr079_t1 as t1')
            ->selectRaw("
                TRIM(CAST(t1.VBELN AS CHAR)) AS VBELN_KEY,
                LPAD(TRIM(CAST(t1.POSNR AS CHAR)), 6, '0') AS POSNR_KEY,
                MAX(
                    CASE
                        WHEN t1.MATNR REGEXP '^[0-9]+$' THEN TRIM(LEADING '0' FROM t1.MATNR)
                        ELSE t1.MATNR
                    END
                ) AS MATNR,
                MAX(t1.MAKTX) AS MAKTX,
                MAX(t1.WAERK) AS WAERK,
                MAX(CAST(t1.TOTPR AS DECIMAL(18,2))) AS TOTPR
            ")
            ->groupBy(
                DB::raw("TRIM(CAST(t1.VBELN AS CHAR))"),
                DB::raw("LPAD(TRIM(CAST(t1.POSNR AS CHAR)), 6, '0')")
            );

        // ============================================================
        // 3) DEDUP T2: 1 baris per (VBELN, WERKS) supaya join tidak dobel
        // ============================================================
        $t2Dedup = DB::table('so_yppr079_t2 as t2')
            ->selectRaw("
                TRIM(CAST(t2.VBELN AS CHAR)) as VBELN_KEY,
                TRIM(CAST(t2.IV_WERKS_PARAM AS CHAR)) as WERKS_KEY,
                MAX(TRIM(t2.BSTNK)) as BSTNK,
                MAX(TRIM(t2.KUNNR)) as KUNNR
            ")
            ->groupBy(
                DB::raw("TRIM(CAST(t2.VBELN AS CHAR))"),
                DB::raw("TRIM(CAST(t2.IV_WERKS_PARAM AS CHAR))")
            );

        // ============================================================
        // 4) QUERY FINAL
        // ============================================================
        $q = DB::query()
            ->fromSub($remarksDedup, 'irx')
            ->joinSub($t1Dedup, 't1d', function ($j) {
                $j->on('irx.VBELN_KEY', '=', 't1d.VBELN_KEY')
                ->on('irx.POSNR_KEY', '=', 't1d.POSNR_KEY');
            })
            ->leftJoinSub($t2Dedup, 't2d', function ($j) {
                $j->on('irx.VBELN_KEY', '=', 't2d.VBELN_KEY')
                ->on('irx.WERKS_KEY', '=', 't2d.WERKS_KEY');
            })
            ->leftJoin('maping as m', function ($j) {
                $j->on('m.IV_AUART', '=', 'irx.AUART_KEY')
                ->on('m.IV_WERKS', '=', 'irx.WERKS_KEY');
            })

            // filter params
            ->when($location, fn($qq, $v) => $qq->where('irx.WERKS_KEY', $v))
            ->when($auart,    fn($qq, $v) => $qq->where('irx.AUART_KEY', $v))
            ->when($vbeln !== '', fn($qq) => $qq->where('irx.VBELN_KEY', $vbeln))

            // filter type (lokal/export)
            ->when($type, function ($qq) use ($type, $exportPlusReplace) {
                if ($type === 'lokal') {
                    $qq->whereExists(function ($ex) {
                        $ex->select(DB::raw(1))
                        ->from('maping as mm')
                        ->whereColumn('mm.IV_AUART', 'irx.AUART_KEY')
                        ->whereColumn('mm.IV_WERKS', 'irx.WERKS_KEY')
                        ->where('mm.Deskription', 'like', '%Local%');
                    });
                } elseif ($type === 'export') {
                    $qq->whereIn('irx.AUART_KEY', $exportPlusReplace);
                }
            })

            ->selectRaw("
                irx.id,
                irx.VBELN_KEY as VBELN,
                TRIM(LEADING '0' FROM irx.POSNR_KEY) as POSNR,
                COALESCE(t2d.BSTNK,'') AS BSTNK,
                COALESCE(t2d.KUNNR,'') AS KUNNR,

                CASE irx.WERKS_KEY
                    WHEN '2000' THEN 'Surabaya'
                    WHEN '3000' THEN 'Semarang'
                    ELSE irx.WERKS_KEY
                END AS PLANT,

                COALESCE(m.Deskription, irx.AUART_KEY) AS ORDER_TYPE,

                COALESCE(t1d.MATNR,'') AS MATNR,
                COALESCE(t1d.MAKTX,'') AS MAKTX,
                COALESCE(t1d.WAERK,'') AS WAERK,
                COALESCE(t1d.TOTPR,0)  AS TOTPR,

                irx.WERKS_KEY as IV_WERKS_PARAM,
                irx.AUART_KEY as IV_AUART_PARAM,
                irx.remark,
                irx.created_at,
                irx.updated_at
            ")
            ->orderBy('irx.VBELN_KEY')
            ->orderByRaw("CAST(irx.POSNR_KEY AS UNSIGNED)")
            ->orderByDesc('irx.created_at');

        $rows = $q->get();
        return response()->json(['ok' => true, 'data' => $rows]);
    }
    private function auartKeysForVbelnSubquery(string $vbeln)
    {
        $vbeln = trim($vbeln);

        return DB::query()->fromSub(function ($u) use ($vbeln) {
            $u->from('so_yppr079_t1 as x')
                ->select(DB::raw('TRIM(x.IV_AUART_PARAM) as AUART'))
                ->whereRaw('TRIM(CAST(x.VBELN AS CHAR)) = TRIM(?)', [$vbeln])
                ->whereNotNull('x.IV_AUART_PARAM')->whereRaw("TRIM(x.IV_AUART_PARAM) <> ''")
            ->unionAll(
                DB::table('so_yppr079_t1 as y')
                    ->select(DB::raw('TRIM(y.AUART2) as AUART'))
                    ->whereRaw('TRIM(CAST(y.VBELN AS CHAR)) = TRIM(?)', [$vbeln])
                    ->whereNotNull('y.AUART2')->whereRaw("TRIM(y.AUART2) <> ''")
            );
        }, 'uu')->select('uu.AUART');
    }
    public function apiItemRemarksForModal(Request $req)
    {
        $vbeln = trim((string) $req->query('vbeln'));
        $posnr = trim((string) $req->query('posnr'));

        if ($vbeln === '' || $posnr === '') {
            return response()->json(['ok' => false, 'error' => 'vbeln/posnr missing'], 400);
        }

        // samakan format posnr DB: 000610
        $posnrDb = str_pad(ltrim($posnr, '0'), 6, '0', STR_PAD_LEFT);

        $auartKeys = $this->auartKeysForVbelnSubquery($vbeln);

        $rows = DB::table('item_remarks as ir')
            ->leftJoin('users as u', 'u.id', '=', 'ir.user_id')
            ->selectRaw("
                ir.id,
                ir.user_id,
                COALESCE(u.name,'Admin') as user_name,
                ir.remark,
                ir.created_at,
                ir.updated_at,
                ir.IV_AUART_PARAM,
                ir.IV_WERKS_PARAM
            ")
            ->whereRaw('TRIM(CAST(ir.VBELN AS CHAR)) = TRIM(?)', [$vbeln])
            ->whereRaw("LPAD(TRIM(CAST(ir.POSNR AS CHAR)),6,'0') = ?", [$posnrDb])
            // ✅ tidak lihat WERKS, tapi pastikan AUART valid utk SO tsb (AUART+AUART2)
            ->whereIn(DB::raw('TRIM(ir.IV_AUART_PARAM)'), $auartKeys)
            ->whereRaw("TRIM(COALESCE(ir.remark,'')) <> ''")
            ->orderBy('ir.created_at', 'desc')
            ->get();

        return response()->json(['ok' => true, 'data' => $rows]);
    }

}
