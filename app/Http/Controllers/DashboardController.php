<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function apiSoUrgencyDetails(Request $request)
    {
        $request->validate([
            'status'   => 'required|string|in:overdue_over_30,overdue_1_30,due_this_week,on_time',
            'location' => 'nullable|string|in:2000,3000',
            'type'     => 'nullable|string|in:lokal,export',
            'auart'    => 'nullable|string', // Filter work center
        ]);

        $status   = $request->query('status');
        $location = $request->query('location');
        $type     = $request->query('type');
        $auart    = $request->query('auart');

        // =================================================================
        // LANGKAH 1: Dapatkan VBELN yang relevan (logika inti dari getSoDashboardData)
        // Ini memastikan kita hanya bekerja pada SO yang memiliki item siap kirim (PACKG > 0)
        // dan sesuai dengan filter yang aktif di dashboard.
        // =================================================================

        $safeEdatu = "COALESCE(
        STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t3.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
        STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t3.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
    )";

        $relevantVbelnsQuery = DB::table('so_yppr079_t3 as t3');

        if ($type === 'lokal') {
            $relevantVbelnsQuery->join('maping as m', function ($join) {
                $join->on('t3.IV_AUART_PARAM', '=', 'm.IV_AUART')
                    ->on('t3.IV_WERKS_PARAM', '=', 'm.IV_WERKS');
            })->where('m.Deskription', 'like', '%Local%');
        } elseif ($type === 'export') {
            $relevantVbelnsQuery->join('maping as m', function ($join) {
                $join->on('t3.IV_AUART_PARAM', '=', 'm.IV_AUART')
                    ->on('t3.IV_WERKS_PARAM', '=', 'm.IV_WERKS');
            })->where('m.Deskription', 'like', '%Export%');
        }

        $relevantVbelnsQuery->when($location, fn($q, $loc) => $q->where('t3.IV_WERKS_PARAM', $loc));
        $relevantVbelnsQuery->when($auart, fn($q, $val) => $q->where('t3.IV_AUART_PARAM', $val));

        // Filter krusial: Hanya SO yang punya item siap kirim (PACKG > 0)
        $relevantVbelnsQuery->whereExists(function ($q) {
            $q->select(DB::raw(1))
                ->from('so_yppr079_t1 as t1_exists')
                ->whereColumn('t1_exists.VBELN', 't3.VBELN')
                ->whereRaw('CAST(t1_exists.PACKG AS DECIMAL(18,3)) > 0');
        });

        $relevantVbelnsQuery->select('t3.VBELN')->distinct();


        // =================================================================
        // LANGKAH 2: Bangun query utama dengan filter status dari chart
        // =================================================================

        $base = DB::table('so_yppr079_t3 as t3')
            ->whereIn('t3.VBELN', $relevantVbelnsQuery); // <-- Hanya proses VBELN yang relevan

        // Terapkan filter status berdasarkan segmen chart yang di-klik
        if ($status === 'overdue_over_30') {
            $base->whereRaw("DATEDIFF(CURDATE(), {$safeEdatu}) > 30");
        } elseif ($status === 'overdue_1_30') {
            $base->whereRaw("DATEDIFF(CURDATE(), {$safeEdatu}) BETWEEN 1 AND 30");
        } elseif ($status === 'due_this_week') {
            // Logika ini harus sama persis dengan yang menghitung angka di chart
            $base->whereRaw("{$safeEdatu} BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
        } else { // on_time
            $base->whereRaw("{$safeEdatu} > DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
        }

        // Ambil data yang dibutuhkan untuk tabel
        $rows = $base
            ->groupBy('t3.VBELN', 't3.BSTNK', 't3.NAME1', 't3.IV_WERKS_PARAM', 't3.IV_AUART_PARAM')
            ->selectRaw("
            t3.VBELN,
            t3.BSTNK,
            t3.NAME1,
            t3.IV_WERKS_PARAM,
            t3.IV_AUART_PARAM,
            DATE_FORMAT(MIN({$safeEdatu}), '%Y-%m-%d') AS due_date
        ")
            ->orderByRaw("MIN({$safeEdatu}) ASC") // Urutkan berdasarkan tanggal jatuh tempo
            ->get();

        return response()->json(['ok' => true, 'data' => $rows]);
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

        // Parser tanggal EDATU yang sama dengan bagian PO dashboard
        $safeEdatu = "
        COALESCE(
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y'),
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d')
        )
    ";

        // Base query mengacu ke logika dashboard PO (t2 + join t1, filter PACKG!=0)
        $base = DB::table('so_yppr079_t2 as t2');

        if ($type === 'lokal') {
            $base->whereIn(DB::raw('(t2.IV_AUART_PARAM, t2.IV_WERKS_PARAM)'), function ($q) {
                $q->select('IV_AUART', 'IV_WERKS')->from('maping')->where('Deskription', 'like', '%Local%')
                    ->union(
                        DB::table('so_yppr079_t2')
                            ->select('IV_AUART_PARAM as IV_AUART', 'IV_WERKS_PARAM as IV_WERKS')
                            ->groupBy('IV_AUART_PARAM', 'IV_WERKS_PARAM')
                            ->havingRaw("SUM(CASE WHEN WAERK = 'IDR' THEN 1 ELSE 0 END) > 0 AND SUM(CASE WHEN WAERK = 'USD' THEN 1 ELSE 0 END) = 0")
                    );
            });
        } elseif ($type === 'export') {
            $base->whereIn(DB::raw('(t2.IV_AUART_PARAM, t2.IV_WERKS_PARAM)'), function ($q) {
                $q->select('IV_AUART', 'IV_WERKS')
                    ->from('maping')
                    ->where('Deskription', 'like', '%Export%')
                    ->where('Deskription', 'not like', '%Replace%')
                    ->where('Deskription', 'not like', '%Local%');
            });
        }

        $base->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc));

        // Join tetap diperlukan, tetapi filter PACKG dihapus
        $base->join(
            'so_yppr079_t1 as t1',
            DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
            '=',
            DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
        );

        // Filter status sesuai pilahan chart
        if ($status === 'overdue') {
            $base->whereRaw("{$safeEdatu} < CURDATE()");
        } elseif ($status === 'due_this_week') {
            $base->whereRaw("{$safeEdatu} >= CURDATE() AND {$safeEdatu} <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
        } else { // on_time
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
          DATE_FORMAT(MIN({$safeEdatu}), '%Y-%m-%d') AS due_date
      ")
            ->orderByRaw("MIN({$safeEdatu}) ASC") // Diurutkan berdasarkan tanggal
            ->get();

        return response()->json(['ok' => true, 'data' => $rows]);
    }
    /**
     * [DISEMPURNAKAN] Fungsi untuk mengambil data khusus untuk dasbor Outstanding SO.
     * Fokus pada item dengan PACKG != 0 (siap kirim).
     */
    private function getSoDashboardData(Request $request)
    {
        $location = $request->query('location'); // Ini adalah WERKS '2000' | '3000' | null
        $type     = $request->query('type');     // 'lokal' | 'export' | null
        $auart    = $request->query('auart');     // [BARU] Filter untuk AUART

        $today     = now()->startOfDay();
        $startWeek = now()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $endWeekEx = (clone $startWeek)->addWeek(); // exclusive

        // Parser tanggal EDATU yang aman (t3)
        $safeEdatu = "COALESCE(
        STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t3.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
        STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t3.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
    )";

        // Parser tanggal EDATU yang aman (t2)
        $safeEdatuT2 = "COALESCE(
        STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
        STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
    )";

        // ==========================
        // 1) Subquery VBELN relevan
        // ==========================
        $relevantVbelnsQuery = DB::table('so_yppr079_t3 as t3');

        if ($type === 'lokal') {
            $relevantVbelnsQuery->join('maping as m', function ($join) {
                $join->on('t3.IV_AUART_PARAM', '=', 'm.IV_AUART')
                    ->on('t3.IV_WERKS_PARAM', '=', 'm.IV_WERKS');
            })->where('m.Deskription', 'like', '%Local%');
        } elseif ($type === 'export') {
            $relevantVbelnsQuery->join('maping as m', function ($join) {
                $join->on('t3.IV_AUART_PARAM', '=', 'm.IV_AUART')
                    ->on('t3.IV_WERKS_PARAM', '=', 'm.IV_WERKS');
            })->where('m.Deskription', 'like', '%Export%');
        }

        // [DIUBAH] Menerapkan filter location (WERKS) dan auart
        $relevantVbelnsQuery->when($location, fn($q, $loc) => $q->where('t3.IV_WERKS_PARAM', $loc));
        $relevantVbelnsQuery->when($auart, fn($q, $val) => $q->where('t3.IV_AUART_PARAM', $val));

        // Hanya SO yang punya item siap kirim (PACKG > 0)
        $relevantVbelnsQuery->whereExists(function ($q) {
            $q->select(DB::raw(1))
                ->from('so_yppr079_t1 as t1_exists')
                ->whereColumn('t1_exists.VBELN', 't3.VBELN')
                ->whereRaw('CAST(t1_exists.PACKG AS DECIMAL(18,3)) > 0');
        });

        $relevantVbelnsQuery->select('t3.VBELN')->distinct();

        // Base query item siap kirim **SELALU** dibatasi VBELN relevan
        $baseQuery = DB::table('so_yppr079_t1 as t1')
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) > 0')
            ->whereIn('t1.VBELN', $relevantVbelnsQuery);

        $chartData = [];

        // ==========================
        // 2) KPI Cards (jumlah SO)
        // ==========================
        $totalOutstandingSoQuery = DB::table('so_yppr079_t3 as t3')
            ->whereIn('t3.VBELN', (clone $relevantVbelnsQuery));

        $totalOutstandingSo = (clone $totalOutstandingSoQuery)->distinct()->count('VBELN');
        $totalOverdueSo     = (clone $totalOutstandingSoQuery)
            ->whereRaw("{$safeEdatu} < ?", [$today])
            ->distinct()->count('VBELN');

        // =========================================================
        // 3) KPI Value Ready to Ship (USD/IDR) — dari t1 (tetap)
        // =========================================================
        $kpiTotalsQuery = DB::table('so_yppr079_t1 as t1')
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) > 0')
            ->whereIn('t1.VBELN', (clone $relevantVbelnsQuery));

        $totalsByCurrency = (clone $kpiTotalsQuery)
            ->selectRaw("
            CAST(SUM(CASE WHEN t1.WAERK = 'USD' THEN CAST(t1.TOTPR2 AS DECIMAL(18,2)) ELSE 0 END) AS DECIMAL(18,2)) AS total_usd,
            CAST(SUM(CASE WHEN t1.WAERK = 'IDR' THEN CAST(t1.TOTPR2 AS DECIMAL(18,2)) ELSE 0 END) AS DECIMAL(18,2)) AS total_idr
        ")->first();

        // =========================================================
        // 3A) Value to Ship This Week — PAKAI t2 (mirror SQL kamu)
        // =========================================================
        $weekAgg = DB::table('so_yppr079_t2 as t2')
            ->when($type === 'lokal', function ($q) {
                $q->join('maping as m', function ($join) {
                    $join->on('t2.IV_AUART_PARAM', '=', 'm.IV_AUART')
                        ->on('t2.IV_WERKS_PARAM', '=', 'm.IV_WERKS');
                })->where('m.Deskription', 'like', '%Local%');
            })
            ->when($type === 'export', function ($q) {
                $q->join('maping as m', function ($join) {
                    $join->on('t2.IV_AUART_PARAM', '=', 'm.IV_AUART')
                        ->on('t2.IV_WERKS_PARAM', '=', 'm.IV_WERKS');
                })->where('m.Deskription', 'like', '%Export%');
            })
            // [DIUBAH] Menerapkan filter location (WERKS) dan auart
            ->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc))
            ->when($auart, fn($q, $val) => $q->where('t2.IV_AUART_PARAM', $val))
            ->whereRaw('CAST(t2.PACKG AS DECIMAL(18,3)) <> 0')
            ->whereRaw("{$safeEdatuT2} >= ? AND {$safeEdatuT2} < ?", [$startWeek, $endWeekEx])
            ->selectRaw("
            CAST(SUM(CASE WHEN TRIM(t2.WAERK)='USD' THEN CAST(t2.TOTPR2 AS DECIMAL(18,2)) ELSE 0 END) AS DECIMAL(18,2)) AS usd,
            CAST(SUM(CASE WHEN TRIM(t2.WAERK)='IDR' THEN CAST(t2.TOTPR2 AS DECIMAL(18,2)) ELSE 0 END) AS DECIMAL(18,2)) AS idr
        ")
            ->first();

        $valueThisWeekUSD = (float) ($weekAgg->usd ?? 0);
        $valueThisWeekIDR = (float) ($weekAgg->idr ?? 0);

        $chartData['kpi'] = [
            'total_outstanding_value_usd' => (float) ($totalsByCurrency->total_usd ?? 0),
            'total_outstanding_value_idr' => (float) ($totalsByCurrency->total_idr ?? 0),

            'total_outstanding_so' => $totalOutstandingSo,
            'total_overdue_so'     => $totalOverdueSo,
            'overdue_rate'         => $totalOutstandingSo > 0 ? ($totalOverdueSo / $totalOutstandingSo) * 100 : 0,

            'value_to_ship_this_week_usd' => $valueThisWeekUSD,
            'value_to_ship_this_week_idr' => $valueThisWeekIDR,

            // Bottleneck dihitung dari baseQuery yang SUDAH dibatasi VBELN relevan
            'potential_bottlenecks' => (clone $baseQuery)
                ->whereRaw('CAST(t1.PACKG AS DECIMAL(15,3)) > CAST(t1.KALAB2 AS DECIMAL(15,3))')
                ->count(),
        ];

        // ==================================================================
        // 4) Value Ready to Ship vs Overdue by Location (tanpa currency)
        // ==================================================================
        $chartData['value_by_location_status'] = DB::table('so_yppr079_t1 as t1')
            ->join('so_yppr079_t3 as t3', 't3.VBELN', '=', 't1.VBELN')
            ->select(
                DB::raw("CASE WHEN t3.IV_WERKS_PARAM = '2000' THEN 'Surabaya' ELSE 'Semarang' END as location"),
                DB::raw("SUM(CASE WHEN {$safeEdatu} >= CURDATE() THEN CAST(t1.TOTPR2 AS DECIMAL(18,2)) ELSE 0 END) as on_time_value"),
                DB::raw("SUM(CASE WHEN {$safeEdatu} <  CURDATE() THEN CAST(t1.TOTPR2 AS DECIMAL(18,2)) ELSE 0 END) as overdue_value")
            )
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) > 0')
            ->whereIn('t1.VBELN', (clone $relevantVbelnsQuery))
            ->groupBy('location')
            ->get();

        // ===========================================================
        // 5) SO Fulfillment Urgency (Aging Analysis) — by SO
        // ===========================================================
        $agingQuery = DB::table('so_yppr079_t3 as t3')
            ->whereIn('t3.VBELN', (clone $relevantVbelnsQuery));

        $chartData['aging_analysis'] = [
            'overdue_over_30' => (clone $agingQuery)->whereRaw("DATEDIFF(CURDATE(), {$safeEdatu}) > 30")->distinct()->count('t3.VBELN'),
            'overdue_1_30'    => (clone $agingQuery)->whereRaw("DATEDIFF(CURDATE(), {$safeEdatu}) BETWEEN 1 AND 30")->distinct()->count('t3.VBELN'),
            'due_this_week'   => (clone $agingQuery)->whereRaw("{$safeEdatu} BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->distinct()->count('t3.VBELN'),
            'on_time'         => (clone $agingQuery)->whereRaw("{$safeEdatu} > DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->distinct()->count('t3.VBELN'),
        ];

        // ===========================================================
        // 6) Top 5 Customers by Value Awaiting Shipment (TOTPR2)
        // ===========================================================
        $topCustomersQuery = DB::table('so_yppr079_t1 as t1')
            ->join('so_yppr079_t3 as t3', 't3.VBELN', '=', 't1.VBELN')
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) > 0')
            ->whereIn('t1.VBELN', (clone $relevantVbelnsQuery));

        $chartData['top_customers_value_usd'] = (clone $topCustomersQuery)
            ->select('t3.NAME1', DB::raw('CAST(SUM(t1.TOTPR2) AS DECIMAL(18,2)) as total_value'))
            ->where('t1.WAERK', 'USD')
            ->groupBy('t3.NAME1')
            ->having('total_value', '>', 0)
            ->orderByDesc('total_value')
            ->limit(5)
            ->get();

        $chartData['top_customers_value_idr'] = (clone $topCustomersQuery)
            ->select('t3.NAME1', DB::raw('CAST(SUM(t1.TOTPR2) AS DECIMAL(18,2)) as total_value'))
            ->where('t1.WAERK', 'IDR')
            ->groupBy('t3.NAME1')
            ->having('total_value', '>', 0)
            ->orderByDesc('total_value')
            ->limit(5)
            ->get();

        // ===========================================================
        // 7) DUE THIS WEEK – pakai t2 (aman untuk ONLY_FULL_GROUP_BY)
        // ===========================================================
        // [MODIFIED] Query untuk tabel SO Due This Week
        $dueThisWeekBySo = DB::table('so_yppr079_t2 as t2')
            ->leftJoin('maping as m', function ($join) {
                $join->on('t2.IV_AUART_PARAM', '=', 'm.IV_AUART')
                    ->on('t2.IV_WERKS_PARAM', '=', 'm.IV_WERKS');
            })
            ->when($type === 'lokal', function ($q) {
                $q->where('m.Deskription', 'like', '%Local%');
            })
            ->when($type === 'export', function ($q) {
                $q->where('m.Deskription', 'like', '%Export%');
            })
            ->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc))
            ->when($auart, fn($q, $val) => $q->where('t2.IV_AUART_PARAM', $val))
            ->whereRaw('CAST(t2.PACKG AS DECIMAL(18,3)) <> 0')
            ->whereRaw("{$safeEdatuT2} >= ? AND {$safeEdatuT2} < ?", [$startWeek, $endWeekEx])
            ->groupBy('t2.VBELN', 't2.BSTNK', 't2.NAME1', 't2.WAERK', 't2.IV_WERKS_PARAM', 't2.IV_AUART_PARAM')
            ->selectRaw("
            t2.VBELN,
            t2.BSTNK,
            t2.NAME1,
            t2.WAERK,
            t2.IV_WERKS_PARAM,
            t2.IV_AUART_PARAM,
            CAST(SUM(t2.TOTPR2) AS DECIMAL(18,2)) AS total_value,
            DATE_FORMAT(MIN({$safeEdatuT2}), '%Y-%m-%d') AS due_date
        ")
            ->orderByDesc('total_value')
            ->limit(50)
            ->get();

        $dueThisWeekByCustomer = DB::table('so_yppr079_t2 as t2')
            ->when($type === 'lokal', function ($q) {
                $q->join('maping as m', function ($join) {
                    $join->on('t2.IV_AUART_PARAM', '=', 'm.IV_AUART')
                        ->on('t2.IV_WERKS_PARAM', '=', 'm.IV_WERKS');
                })->where('m.Deskription', 'like', '%Local%');
            })
            ->when($type === 'export', function ($q) {
                $q->join('maping as m', function ($join) {
                    $join->on('t2.IV_AUART_PARAM', '=', 'm.IV_AUART')
                        ->on('t2.IV_WERKS_PARAM', '=', 'm.IV_WERKS');
                })->where('m.Deskription', 'like', '%Export%');
            })
            // [DIUBAH] Menerapkan filter location (WERKS) dan auart
            ->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc))
            ->when($auart, fn($q, $val) => $q->where('t2.IV_AUART_PARAM', $val))
            ->whereRaw('CAST(t2.PACKG AS DECIMAL(18,3)) <> 0')
            ->whereRaw("{$safeEdatuT2} >= ? AND {$safeEdatuT2} < ?", [$startWeek, $endWeekEx])
            ->groupBy('t2.NAME1', 't2.WAERK')
            ->selectRaw("
            t2.NAME1,
            t2.WAERK,
            CAST(SUM(t2.TOTPR2) AS DECIMAL(18,2)) AS total_value
        ")
            ->orderByDesc('total_value')
            ->get();

        $chartData['due_this_week'] = [
            'start'       => $startWeek->toDateTimeString(),
            'end_excl'    => $endWeekEx->toDateTimeString(),
            'by_so'       => $dueThisWeekBySo,
            'by_customer' => $dueThisWeekByCustomer,
        ];

        return $chartData;
    }


    /**
     * Metode utama untuk menampilkan dasbor atau laporan.
     */
    public function index(Request $request)
    {

        if ($request->filled('werks') && !$request->filled('auart')) {
            // Ambil mapping untuk menemukan tipe default (Export)
            $mapping = DB::table('maping')
                ->select('IV_WERKS', 'IV_AUART', 'Deskription')
                ->where('IV_WERKS', $request->werks)
                ->orderBy('IV_AUART')
                ->get();

            // Cari tipe 'Export' pertama yang tersedia
            $defaultType = $mapping->first(function ($item) {
                return str_contains(strtolower($item->Deskription), 'export');
            });

            // Jika tidak ada tipe Export, ambil saja tipe pertama yang ada sebagai fallback
            if (!$defaultType) {
                $defaultType = $mapping->first();
            }

            // Jika ada tipe yang bisa dipilih, redirect ke URL lengkap dengan tipe default
            if ($defaultType) {
                $params = array_merge($request->query(), ['auart' => $defaultType->IV_AUART]);
                return redirect()->route('dashboard', $params);
            }
        }
        // =======================================================================
        // == AKHIR BLOK KODE TAMBAHAN ==
        // =======================================================================

        $werks = $request->query('werks');
        $auart = $request->query('auart');
        $location = $request->query('location');
        $type = $request->query('type');
        $view = $request->query('view', 'po');

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
        $chartData = [];
        $selectedLocationName = 'All Locations';
        $selectedTypeName = 'All Types';

        if ($show) {
            // Logika untuk halaman laporan detail PO
            $safeEdatu = "COALESCE(STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'), STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y'))";
            $safeEdatuInner = "COALESCE(STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2_inner.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'), STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2_inner.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y'))";

            $query = DB::table('so_yppr079_t2 as t2')
                ->leftJoin(DB::raw('(
                SELECT t2_inner.KUNNR, SUM(t1.TOTPR) AS TOTAL_TOTPR
                FROM so_yppr079_t2 AS t2_inner
                LEFT JOIN so_yppr079_t1 AS t1
                ON TRIM(CAST(t1.VBELN AS CHAR)) = TRIM(CAST(t2_inner.VBELN AS CHAR))
                WHERE t2_inner.IV_WERKS_PARAM = ' . DB::getPdo()->quote($werks) . '
                AND t2_inner.IV_AUART_PARAM = ' . DB::getPdo()->quote($auart) . '
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
                ->orderBy('NAME1');

            $rows = $query->paginate(25)->withQueryString();
            $selectedMapping = DB::table('maping')
                ->where('IV_WERKS', $werks)
                ->where('IV_AUART', $auart)
                ->first();
            $selectedDescription = $selectedMapping->Deskription ?? '';
        } else {
            // Logika untuk memilih data dasbor mana yang akan dimuat
            if ($view === 'so') {
                $chartData = $this->getSoDashboardData($request);
            } else {
                // Logika untuk dasbor PO (kode lama Anda, dengan perbaikan)
                $today = now()->startOfDay();
                if ($location === '2000') $selectedLocationName = 'Surabaya';
                if ($location === '3000') $selectedLocationName = 'Semarang';
                $safeEdatu = "
                COALESCE(
                    STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y'),
                    STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d')
                )";
                $baseQuery = DB::table('so_yppr079_t2 as t2');
                if ($type === 'lokal') {
                    $selectedTypeName = 'Lokal';
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
                    $baseQuery->whereIn(DB::raw('(t2.IV_AUART_PARAM, t2.IV_WERKS_PARAM)'), function ($query) {
                        $query->select('IV_AUART', 'IV_WERKS')
                            ->from('maping')
                            ->where('Deskription', 'like', '%Export%')
                            ->where('Deskription', 'not like', '%Replace%')
                            ->where('Deskription', 'not like', '%Local%');
                    });
                }
                $baseQuery->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc));

                // [FIX 1] Query untuk kalkulasi KPI value sekarang harus join dan filter PACKG
                $kpiQuery = (clone $baseQuery)
                    ->leftJoin('so_yppr079_t1 as t1', DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'))
                    ->where('t1.PACKG', '!=', 0); // <-- Filter utama ditambahkan di sini

                $chartData['kpi'] = [
                    'total_outstanding_value_usd' => (clone $kpiQuery)->where('t2.WAERK', 'USD')->sum('t1.TOTPR'),
                    'total_outstanding_value_idr' => (clone $kpiQuery)->where('t2.WAERK', 'IDR')->sum('t1.TOTPR'),
                    'total_outstanding_so'        => (clone $baseQuery)->distinct()->count('t2.VBELN'), // Total SO tidak perlu filter PACKG
                    'total_overdue_so'            => (clone $baseQuery)->whereRaw("{$safeEdatu} < ?", [$today])->distinct()->count('t2.VBELN'), // Overdue juga tidak
                ];

                if ($chartData['kpi']['total_outstanding_so'] > 0) {
                    $chartData['kpi']['overdue_rate'] = ($chartData['kpi']['total_overdue_so'] / $chartData['kpi']['total_outstanding_so']) * 100;
                } else {
                    $chartData['kpi']['overdue_rate'] = 0;
                }

                $chartData['so_status'] = [
                    'overdue'       => $chartData['kpi']['total_overdue_so'],
                    'due_this_week' => (clone $baseQuery)
                        ->whereRaw("{$safeEdatu} BETWEEN ? AND ?", [$today, $today->copy()->addDays(7)])
                        ->distinct()->count('t2.VBELN'),
                    'on_time'       => (clone $baseQuery)
                        ->whereRaw("{$safeEdatu} > ?", [$today->copy()->addDays(7)])
                        ->distinct()->count('t2.VBELN'),
                ];

                // [FIX 1.A] Query untuk chart outstanding by location juga harus difilter PACKG agar konsisten
                $chartData['outstanding_by_location'] = DB::table('so_yppr079_t2 as t2')
                    ->join('so_yppr079_t1 as t1', DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'))
                    ->select(
                        DB::raw("CASE WHEN t2.IV_WERKS_PARAM = '2000' THEN 'Surabaya' ELSE 'Semarang' END as location"),
                        't2.WAERK as currency',
                        DB::raw('SUM(t1.TOTPR) as total_value'),
                        DB::raw('COUNT(DISTINCT t2.VBELN) as so_count')
                    )
                    ->where('t1.PACKG', '!=', 0) // <-- Filter ditambahkan di sini
                    ->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc))
                    ->groupBy('location', 'currency')
                    ->get();

                // [FIX 1.B] Perbaikan query untuk Top Customer by Value (PO Dashboard)
                $chartData['top_customers_value_usd'] = (clone $baseQuery)
                    ->join('so_yppr079_t1 as t1', DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'))
                    ->select('t2.NAME1', DB::raw('SUM(t1.TOTPR) as total_value'), DB::raw('COUNT(DISTINCT t2.VBELN) as so_count'))
                    ->where('t1.PACKG', '!=', 0) // <-- Filter ditambahkan di sini
                    ->where('t2.WAERK', 'USD')->groupBy('t2.NAME1')->having('total_value', '>', 0)
                    ->orderByDesc('total_value')->limit(4)->get();

                $chartData['top_customers_value_idr'] = (clone $baseQuery)
                    ->join('so_yppr079_t1 as t1', DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'))
                    ->select('t2.NAME1', DB::raw('SUM(t1.TOTPR) as total_value'), DB::raw('COUNT(DISTINCT t2.VBELN) as so_count'))
                    ->where('t1.PACKG', '!=', 0) // <-- Filter ditambahkan di sini
                    ->where('t2.WAERK', 'IDR')->groupBy('t2.NAME1')->having('total_value', '>', 0)
                    ->orderByDesc('total_value')->limit(4)->get();

                $chartData['top_customers_overdue'] = (clone $baseQuery)
                    ->select(
                        't2.NAME1',
                        DB::raw('COUNT(DISTINCT t2.VBELN) as overdue_count'),
                        DB::raw('GROUP_CONCAT(DISTINCT t2.IV_WERKS_PARAM ORDER BY t2.IV_WERKS_PARAM ASC SEPARATOR ", ") as locations'),
                        DB::raw("COUNT(DISTINCT CASE WHEN t2.IV_WERKS_PARAM = '3000' THEN t2.VBELN ELSE NULL END) as smg_count"),
                        DB::raw("COUNT(DISTINCT CASE WHEN t2.IV_WERKS_PARAM = '2000' THEN t2.VBELN ELSE NULL END) as sby_count")
                    )
                    ->whereRaw("{$safeEdatu} < CURDATE()")->groupBy('t2.NAME1')
                    ->orderByDesc('overdue_count')->limit(4)->get();

                $performanceQueryBase = DB::table('maping as m')
                    ->join('so_yppr079_t2 as t2', function ($join) {
                        $join->on('m.IV_WERKS', '=', 't2.IV_WERKS_PARAM')
                            ->on('m.IV_AUART', '=', 't2.IV_AUART_PARAM');
                    })
                    ->leftJoin('so_yppr079_t1 as t1', DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'));

                $typesToFilter = null;
                if ($type === 'lokal' || $type === 'export') {
                    $cloneForFilter = (clone $baseQuery)->select('t2.IV_AUART_PARAM', 't2.IV_WERKS_PARAM')->distinct();
                    $typesToFilter = $cloneForFilter->get()->map(fn($item) => $item->IV_AUART_PARAM . '-' . $item->IV_WERKS_PARAM)->toArray();
                }
                if ($typesToFilter !== null) {
                    $performanceQueryBase->whereIn(DB::raw("CONCAT(m.IV_AUART, '-', m.IV_WERKS)"), $typesToFilter);
                }
                $performanceQuery = $performanceQueryBase->select(
                    'm.Deskription',
                    'm.IV_WERKS',
                    DB::raw('COUNT(DISTINCT t2.VBELN) as total_so'),
                    DB::raw("SUM(CASE WHEN t2.WAERK = 'IDR' AND {$safeEdatu} < CURDATE() THEN t1.TOTPR ELSE 0 END) as total_value_idr"),
                    DB::raw("SUM(CASE WHEN t2.WAERK = 'USD' AND {$safeEdatu} < CURDATE() THEN t1.TOTPR ELSE 0 END) as total_value_usd"),
                    DB::raw("COUNT(DISTINCT CASE WHEN {$safeEdatu} < CURDATE() THEN t2.VBELN ELSE NULL END) as overdue_so_count"),
                    DB::raw("COUNT(DISTINCT CASE WHEN DATEDIFF(CURDATE(), {$safeEdatu}) BETWEEN 1 AND 30 THEN t2.VBELN ELSE NULL END) as overdue_1_30"),
                    DB::raw("COUNT(DISTINCT CASE WHEN DATEDIFF(CURDATE(), {$safeEdatu}) BETWEEN 31 AND 60 THEN t2.VBELN ELSE NULL END) as overdue_31_60"),
                    DB::raw("COUNT(DISTINCT CASE WHEN DATEDIFF(CURDATE(), {$safeEdatu}) BETWEEN 61 AND 90 THEN t2.VBELN ELSE NULL END) as overdue_61_90"),
                    DB::raw("COUNT(DISTINCT CASE WHEN DATEDIFF(CURDATE(), {$safeEdatu}) > 90 THEN t2.VBELN ELSE NULL END) as overdue_over_90")
                )
                    ->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc))
                    ->groupBy('m.IV_WERKS', 'm.Deskription')
                    ->orderBy('m.IV_WERKS')->orderBy('m.Deskription')->get();
                $chartData['so_performance_analysis'] = $performanceQuery;

                $smallQtyByCustomerQuery = (clone $baseQuery)
                    ->join('so_yppr079_t1 as t1', DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'))
                    ->select('t2.NAME1', 't2.IV_WERKS_PARAM', DB::raw('COUNT(t1.POSNR) as item_count'))
                    ->where('t1.QTY_BALANCE2', '>', 0)->where('t1.QTY_BALANCE2', '<=', 5)
                    ->groupBy('t2.NAME1', 't2.IV_WERKS_PARAM')->orderBy('t2.NAME1')->get();
                $chartData['small_qty_by_customer'] = $smallQtyByCustomerQuery;
            }
        }

        return view('dashboard', [
            'mapping'              => $mapping,
            'selected'             => ['werks' => $werks, 'auart' => $auart],
            'selectedDescription'  => $selectedDescription,
            'rows'                 => $rows,
            'compact'              => $compact,
            'show'                 => $show,
            'chartData'            => $chartData,
            'selectedLocation'     => $location,
            'selectedLocationName' => $selectedLocationName,
            'selectedType'         => $type,
            'selectedTypeName'     => $selectedTypeName,
            'view'                 => $view,
        ]);
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
            })
            ->get();

        $today = new \DateTime();
        $today->setTime(0, 0, 0);
        foreach ($rows as $row) {
            $overdue = 0;
            $row->FormattedEdatu = '';
            if (!empty($row->EDATU) && $row->EDATU !== '0000-00-00') {
                try {
                    $edatuDate = \DateTime::createFromFormat('Y-m-d', $row->EDATU) ?: \DateTime::createFromFormat('d-m-Y', $row->EDATU);
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
            ->select(
                DB::raw("TRIM(LEADING '0' FROM t1.POSNR) as POSNR"),
                DB::raw("CASE WHEN t1.MATNR REGEXP '^[0-9]+$' THEN TRIM(LEADING '0' FROM t1.MATNR) ELSE t1.MATNR END as MATNR"),
                't1.MAKTX',
                't1.KWMENG',
                't1.QTY_GI',
                't1.QTY_BALANCE2',
                't1.NETPR',
                't1.TOTPR',
                't1.NETWR',
                't1.WAERK',
                't1.KALAB'
            )
            ->where($matchVbeln)
            ->when(strlen((string)$werks) > 0, function ($q) use ($werks) {
                $q->where(function ($qq) use ($werks) {
                    $qq->where('t1.WERKS', $werks)->orWhere('t1.IV_WERKS_PARAM', $werks)
                        ->orWhereRaw('TRIM(CAST(t1.WERKS AS CHAR)) = TRIM(?)', [$werks])
                        ->orWhereRaw('TRIM(CAST(t1.IV_WERKS_PARAM AS CHAR)) = TRIM(?)', [$werks]);
                });
            })
            ->when(strlen((string)$auart) > 0, function ($q) use ($auart) {
                $q->where(function ($qq) use ($auart) {
                    $qq->where('t1.AUART', $auart)->orWhere('t1.IV_AUART_PARAM', $auart)
                        ->orWhereRaw('TRIM(t1.AUART) = TRIM(?)', [$auart])
                        ->orWhereRaw('TRIM(t1.IV_AUART_PARAM) = TRIM(?)', [$auart]);
                });
            })
            ->orderByRaw('LPAD(TRIM(CAST(t1.POSNR AS CHAR)), 6, "0")')
            ->get();
        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function search(Request $request)
    {
        $request->validate(['term' => 'required|string|max:100']);
        $term_searched = $request->input('term');
        $so_info = DB::table('so_yppr079_t2')
            ->where(function ($query) use ($term_searched) {
                $query->whereRaw('TRIM(CAST(VBELN AS CHAR)) = ?', [$term_searched])
                    ->orWhereRaw('TRIM(CAST(BSTNK AS CHAR)) = ?', [$term_searched]);
            })
            ->select('IV_WERKS_PARAM', 'IV_AUART_PARAM', 'KUNNR', 'VBELN')
            ->first();
        if ($so_info) {
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
        return back()->withErrors(['term' => 'Nomor PO atau SO "' . $term_searched . '" tidak ditemukan.'])->withInput();
    }

    public function apiSmallQtyDetails(Request $request)
    {
        $request->validate(['customerName' => 'required|string', 'locationName' => 'required|string|in:Semarang,Surabaya']);
        $customerName = $request->query('customerName');
        $locationName = $request->query('locationName');
        $type = $request->query('type');
        $werks = ($locationName === 'Semarang') ? '3000' : '2000';
        $query = DB::table('so_yppr079_t1 as t1')
            ->join('so_yppr079_t2 as t2', DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'))
            ->join('maping as m', function ($join) {
                $join->on('t2.IV_AUART_PARAM', '=', 'm.IV_AUART')
                    ->on('t2.IV_WERKS_PARAM', '=', 'm.IV_WERKS');
            })
            ->where('t2.NAME1', $customerName)
            ->where('t2.IV_WERKS_PARAM', $werks)
            ->where('t1.QTY_BALANCE2', '>', 0)
            ->where('t1.QTY_BALANCE2', '<=', 5);
        if ($type === 'lokal') {
            $query->where(function ($q) {
                $q->where('m.Deskription', 'like', '%Local%')
                    ->orWhere('m.Deskription', 'like', '%Replace%');
            });
        } elseif ($type === 'export') {
            $query->where('m.Deskription', 'like', '%Export%');
        }
        $items = $query->select(
            't2.VBELN',
            't2.BSTNK',
            't1.POSNR',
            't1.MAKTX',
            't1.KWMENG',
            't1.QTY_BALANCE2'
        )
            ->orderBy('t2.VBELN', 'asc')->orderBy('t1.POSNR', 'asc')->get();
        return response()->json(['ok' => true, 'data' => $items]);
    }
}
