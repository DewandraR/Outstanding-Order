<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
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
            ->groupBy('VBELN')->orderByDesc('item_count')->limit(10)->get();

        return response()->json([
            'ok' => true,
            'data' => [
                'total_item_remarks'  => $totalItemRemarks,
                'total_so_with_remarks' => $totalSoWithRemarks,
                'top_so'              => $bySo,
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

        $rows = DB::table('item_remarks as ir')
            ->leftJoin('so_yppr079_t1 as t1', function ($j) {
                $j->on(DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(ir.VBELN AS CHAR))'))
                    ->on(DB::raw('LPAD(TRIM(CAST(t1.POSNR AS CHAR)),6,"0")'), '=', DB::raw('LPAD(TRIM(CAST(ir.POSNR AS CHAR)),6,"0")'));
            })
            // [BARIS BARU] Tambahkan join ke t2 untuk mendapatkan KUNNR
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
            ") // [DIUBAH] Tambahkan KUNNR dari t2 ke select
            ->whereNotNull('ir.remark')->whereRaw('TRIM(ir.remark) <> ""')
            ->when($location,       fn($q, $v) => $q->where('ir.IV_WERKS_PARAM', $v))
            ->when($effectiveAuart, fn($q, $v) => $q->where('ir.IV_AUART_PARAM', $v))
            ->when($vbeln !== '',   fn($q) => $q->whereRaw('TRIM(CAST(ir.VBELN AS CHAR)) = TRIM(?)', [$vbeln]))
            ->orderBy('ir.VBELN')->orderByRaw('LPAD(TRIM(CAST(ir.POSNR AS CHAR)),6,"0")')
            ->limit(2000)
            ->get();

        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function apiSoBottlenecksDetails(Request $request)
    {
        $request->validate([
            'location' => 'nullable|string|in:2000,3000',
            'type'     => 'nullable|string|in:lokal,export',
            'auart'    => 'nullable|string',
        ]);

        $location = $request->query('location'); // 2000|3000|null
        $type     = $request->query('type');     // lokal|export|null
        $auart    = $request->query('auart');    // optional

        // Parser EDATU untuk T2 (dipakai min due_date per SO)
        $safeEdatuT2 = "COALESCE(
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
        )";

        // === AUART efektif (SAMAKAN DENGAN KPI DI getSoDashboardData)
        $effectiveAuart = $auart;
        if (!$effectiveAuart && $location && $type) {
            $effectiveAuart = DB::table('maping')
                ->where('IV_WERKS', $location)
                ->when($type === 'export', function ($q) {
                    $q->where('Deskription', 'like', '%Export%')
                        ->where('Deskription', 'not like', '%Replace%')
                        ->where('Deskription', 'not like', '%Local%');
                })
                ->when($type === 'lokal', function ($q) {
                    $q->where('Deskription', 'like', '%Local%');
                })
                ->orderBy('IV_AUART')
                ->value('IV_AUART');
        }

        // === VBELN relevan (SAMAKAN DENGAN KPI): outstanding & sesuai lokasi+AUART efektif
        $relevantVbelnsQuery = DB::table('so_yppr079_t3 as t3')
            ->when($location,       fn($q, $loc) => $q->where('t3.IV_WERKS_PARAM', $loc))
            ->when($effectiveAuart, fn($q, $v)   => $q->where('t3.IV_AUART_PARAM', $v))
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('so_yppr079_t1 as t1_exists')
                    ->whereColumn('t1_exists.VBELN', 't3.VBELN')
                    ->whereRaw('CAST(t1_exists.PACKG AS DECIMAL(18,3)) <> 0');
            })
            ->select('t3.VBELN')
            ->distinct();

        // === LOGIKA BOTTLENECK (SAMAKAN DENGAN KPI): item outstanding & PACKG > KALAB2
        $rows = DB::table('so_yppr079_t1 as t1')
            ->join('so_yppr079_t2 as t2', DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'))
            ->whereIn('t1.VBELN', $relevantVbelnsQuery)
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(15,3)) > 0')
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(15,3)) > CAST(t1.KALAB2 AS DECIMAL(15,3))')
            // (opsional) jaga-jaga filter eksplisit lokasi/AUART bila dikirim langsung:
            ->when($location,       fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc))
            ->when($effectiveAuart, fn($q, $v)   => $q->where('t2.IV_AUART_PARAM', $v))
            // === tetap keluarkan 1 baris per SO dengan due date terawal
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

        return response()->json(['ok' => true, 'data' => $rows]);
    }
    public function apiPoOverdueDetails(Request $request)
    {
        $request->validate([
            'werks'  => 'required|string',               // '2000' | '3000'
            'auart'  => 'required|string',               // contoh: 'ZRP2', 'ZOR4', dst.
            'bucket' => 'required|string|in:1_30,31_60,61_90,gt_90',
        ]);

        $werks  = $request->query('werks');
        $auart  = $request->query('auart');
        $bucket = $request->query('bucket');

        // Parser tanggal: EDATU dari T2 saja
        $safeEdatu = "COALESCE(
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
        )";

        $q = DB::table('so_yppr079_t2 as t2')
            ->selectRaw("
                TRIM(t2.BSTNK)                            AS PO,
                TRIM(t2.VBELN)                            AS SO,
                DATE_FORMAT($safeEdatu, '%Y-%m-%d')       AS EDATU,
                DATEDIFF(CURDATE(), $safeEdatu)           AS OVERDUE_DAYS
            ")
            ->where('t2.IV_WERKS_PARAM', $werks)
            ->where('t2.IV_AUART_PARAM', $auart);

        // Filter bucket hari
        switch ($bucket) {
            case '1_30':
                $q->whereRaw("DATEDIFF(CURDATE(), $safeEdatu) BETWEEN 1 AND 30");
                break;
            case '31_60':
                $q->whereRaw("DATEDIFF(CURDATE(), $safeEdatu) BETWEEN 31 AND 60");
                break;
            case '61_90':
                $q->whereRaw("DATEDIFF(CURDATE(), $safeEdatu) BETWEEN 61 AND 90");
                break;
            case 'gt_90':
                $q->whereRaw("DATEDIFF(CURDATE(), $safeEdatu) > 90");
                break;
        }

        $rows = $q->orderByDesc('OVERDUE_DAYS')
            ->orderBy('t2.VBELN')
            ->orderBy('t2.BSTNK')
            ->limit(2000)
            ->get();

        return response()->json(['ok' => true, 'data' => $rows]);
    }
    /* ======================================================================
     * API DETAIL (unchanged)
     * ====================================================================*/

    public function apiSoUrgencyDetails(Request $request)
    {
        $request->validate([
            'status'   => 'required|string|in:overdue_over_30,overdue_1_30,due_this_week,on_time',
            'location' => 'nullable|string|in:2000,3000',
            'type'     => 'nullable|string|in:lokal,export',
            'auart'    => 'nullable|string',
        ]);

        $status   = $request->query('status');
        $location = $request->query('location');   // '2000' | '3000' | null
        $type     = $request->query('type');       // 'lokal' | 'export' | null
        $auart    = $request->query('auart');      // optional

        // === Parser tanggal aman utk EDATU (format campuran di DB)
        $safeEdatu = "COALESCE(
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t3.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t3.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
        )";

        // === Samakan "AUART efektif" seperti dashboard SO
        $effectiveAuart = $auart;
        if (!$effectiveAuart && $location && $type) {
            $effectiveAuart = DB::table('maping')
                ->where('IV_WERKS', $location)
                ->when($type === 'export', function ($q) {
                    $q->where('Deskription', 'like', '%Export%')
                        ->where('Deskription', 'not like', '%Replace%')
                        ->where('Deskription', 'not like', '%Local%');
                })
                ->when($type === 'lokal', function ($q) {
                    // Ikuti SO Report: untuk "lokal" ambil yang Local (bukan Replace)
                    $q->where('Deskription', 'like', '%Local%');
                })
                ->orderBy('IV_AUART')
                ->value('IV_AUART');
        }

        // === Subquery VBELN relevan (hanya yang masih outstanding: PACKG > 0)
        $relevantVbelnsQuery = DB::table('so_yppr079_t3 as t3')
            ->when($type === 'lokal', function ($q) {
                $q->join('maping as m', function ($j) {
                    $j->on('t3.IV_AUART_PARAM', '=', 'm.IV_AUART')
                        ->on('t3.IV_WERKS_PARAM', '=', 'm.IV_WERKS');
                })->where('m.Deskription', 'like', '%Local%');
            })
            ->when($type === 'export', function ($q) {
                $q->join('maping as m', function ($j) {
                    $j->on('t3.IV_AUART_PARAM', '=', 'm.IV_AUART')
                        ->on('t3.IV_WERKS_PARAM', '=', 'm.IV_WERKS');
                })->where('m.Deskription', 'like', '%Export%');
            })
            ->when($location,       fn($q, $loc) => $q->where('t3.IV_WERKS_PARAM', $loc))
            ->when($effectiveAuart, fn($q, $v)   => $q->where('t3.IV_AUART_PARAM', $v))
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('so_yppr079_t1 as t1_exists')
                    ->whereColumn('t1_exists.VBELN', 't3.VBELN')
                    ->whereRaw('CAST(t1_exists.PACKG AS DECIMAL(18,3)) <> 0');
            })
            ->select('t3.VBELN')
            ->distinct();

        // === Basis data yang akan diambil detailnya
        $base = DB::table('so_yppr079_t3 as t3')->whereIn('t3.VBELN', $relevantVbelnsQuery);

        // === Filter status sesuai donut
        if ($status === 'overdue_over_30') {
            $base->whereRaw("DATEDIFF(CURDATE(), {$safeEdatu}) > 30");
        } elseif ($status === 'overdue_1_30') {
            $base->whereRaw("DATEDIFF(CURDATE(), {$safeEdatu}) BETWEEN 1 AND 30");
        } elseif ($status === 'due_this_week') {
            $base->whereRaw("{$safeEdatu} BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
        } else { // 'on_time'
            $base->whereRaw("{$safeEdatu} > DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
        }

        // === Keluarkan list unik per SO (VBELN) plus due date terawal
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
            ->orderByRaw("MIN({$safeEdatu}) ASC")
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
            $base->whereIn(DB::raw('(t2.IV_AUART_PARAM, t2.IV_WERKS_PARAM)'), function ($query) {
                $query->select('IV_AUART', 'IV_WERKS')
                    ->from('maping')
                    ->where('Deskription', 'like', '%Export%')
                    ->where('Deskription', 'not like', '%Replace%')
                    ->where('Deskription', 'not like', '%Local%');
            });
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
                DATE_FORMAT(MIN({$safeEdatu}), '%Y-%m-%d') AS due_date
            ")
            ->orderByRaw("MIN({$safeEdatu}) ASC")
            ->get();

        return response()->json(['ok' => true, 'data' => $rows]);
    }

    /* ======================================================================
     * SO DASHBOARD DATA (KPI SO diambil langsung dari logika SO Report)
     * ====================================================================*/

    private function getSoDashboardData(Request $request)
    {
        $location = $request->query('location'); // '2000' | '3000' | null
        $type     = $request->query('type');     // 'lokal' | 'export' | null
        $auart    = $request->query('auart');    // optional

        $today     = now()->startOfDay();
        $startWeek = now()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $endWeekEx = (clone $startWeek)->addWeek(); // exclusive

        // Parser tanggal aman (T3 & T2)
        $safeEdatu = "COALESCE(
        STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t3.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
        STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t3.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
    )";
        $safeEdatuT2 = "COALESCE(
        STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
        STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
    )";

        // ===== Tentukan AUART efektif bila tidak dikirim, mengikuti default SO Report per lokasi+type =====
        $effectiveAuart = $auart;
        if (!$effectiveAuart && $location && $type) {
            $effectiveAuart = DB::table('maping')
                ->where('IV_WERKS', $location)
                ->when($type === 'export', function ($q) {
                    $q->where('Deskription', 'like', '%Export%')
                        ->where('Deskription', 'not like', '%Replace%')
                        ->where('Deskription', 'not like', '%Local%');
                })
                ->when($type === 'lokal', function ($q) {
                    // Di report Anda, Local & Replace dipisah. Untuk "type=lokal" kita pilih default Local.
                    $q->where('Deskription', 'like', '%Local%');
                })
                ->orderBy('IV_AUART')
                ->value('IV_AUART'); // ambil satu default (seperti SO Report redirect)
        }

        // ===== Subquery VBELN relevan untuk panel lain (PACKG > 0) =====
        $relevantVbelnsQuery = DB::table('so_yppr079_t3 as t3')
            ->when($location, fn($q, $loc) => $q->where('t3.IV_WERKS_PARAM', $loc))
            ->when($effectiveAuart, fn($q, $v) => $q->where('t3.IV_AUART_PARAM', $v))
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('so_yppr079_t1 as t1_exists')
                    ->whereColumn('t1_exists.VBELN', 't3.VBELN')
                    ->whereRaw('CAST(t1_exists.PACKG AS DECIMAL(18,3)) <> 0');
            })
            ->select('t3.VBELN')
            ->distinct();

        $baseQuery = DB::table('so_yppr079_t1 as t1')
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) > 0')
            ->whereIn('t1.VBELN', $relevantVbelnsQuery);

        $chartData = [];

        // ===== KPI JUMLAH SO (distinct VBELN) =====
        $totalOutstandingSoQuery = DB::table('so_yppr079_t3 as t3')
            ->whereIn('t3.VBELN', (clone $relevantVbelnsQuery));
        $totalOutstandingSo = (clone $totalOutstandingSoQuery)->distinct()->count('VBELN');
        $totalOverdueSo     = (clone $totalOutstandingSoQuery)
            ->whereRaw("{$safeEdatu} < ?", [$today])
            ->distinct()->count('VBELN');

        // ======= KPI VALUE: TOTAL outstanding (bukan hanya overdue) =======
        $reportAgg = DB::table('so_yppr079_t2 as t2')
            ->join(
                'so_yppr079_t1 as t1',
                DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                '=',
                DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
            )
            ->when($location,       fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc))
            ->when($effectiveAuart, fn($q, $v)   => $q->where('t2.IV_AUART_PARAM', $v))
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) <> 0')   // outstanding saja
            // ❌ tidak ada filter overdue di sini
            ->selectRaw("
            TRIM(t1.WAERK) as cur,
            CAST(SUM(CAST(t1.TOTPR AS DECIMAL(18,2))) AS DECIMAL(18,2)) as amt
        ")
            ->groupBy('cur')
            ->get();

        $reportUsd = (float) optional($reportAgg->firstWhere('cur', 'USD'))->amt ?? 0.0;
        $reportIdr = (float) optional($reportAgg->firstWhere('cur', 'IDR'))->amt ?? 0.0;

        // kirim ke Blade (dipakai tile KPI SO)
        $chartData['so_report_totals'] = ['usd' => $reportUsd, 'idr' => $reportIdr];

        // ===== Isi KPI utama (USD/IDR ambil dari so_report_totals) =====
        $chartData['kpi'] = [
            'total_outstanding_value_usd' => $reportUsd,
            'total_outstanding_value_idr' => $reportIdr,
            'total_outstanding_so'        => $totalOutstandingSo,
            'total_overdue_so'            => $totalOverdueSo,
            'overdue_rate'                => $totalOutstandingSo > 0 ? ($totalOverdueSo / $totalOutstandingSo) * 100 : 0,
            // Value to ship this week (tetap dari T2, window minggu berjalan, PACKG <> 0)
            'value_to_ship_this_week_usd' => (float) (DB::table('so_yppr079_t2 as t2')
                ->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc))
                ->when($effectiveAuart, fn($q, $v) => $q->where('t2.IV_AUART_PARAM', $v))
                ->whereRaw('CAST(t2.PACKG AS DECIMAL(18,3)) <> 0')
                ->whereRaw("{$safeEdatuT2} >= ? AND {$safeEdatuT2} < ?", [$startWeek, $endWeekEx])
                ->selectRaw("CAST(SUM(CASE WHEN TRIM(t2.WAERK)='USD' THEN CAST(t2.TOTPR2 AS DECIMAL(18,2)) ELSE 0 END) AS DECIMAL(18,2)) AS usd")
                ->value('usd') ?? 0),
            'value_to_ship_this_week_idr' => (float) (DB::table('so_yppr079_t2 as t2')
                ->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc))
                ->when($effectiveAuart, fn($q, $v) => $q->where('t2.IV_AUART_PARAM', $v))
                ->whereRaw('CAST(t2.PACKG AS DECIMAL(18,3)) <> 0')
                ->whereRaw("{$safeEdatuT2} >= ? AND {$safeEdatuT2} < ?", [$startWeek, $endWeekEx])
                ->selectRaw("CAST(SUM(CASE WHEN TRIM(t2.WAERK)='IDR' THEN CAST(t2.TOTPR2 AS DECIMAL(18,2)) ELSE 0 END) AS DECIMAL(18,2)) AS idr")
                ->value('idr') ?? 0),
            'potential_bottlenecks' => (clone $baseQuery)
                ->whereRaw('CAST(t1.PACKG AS DECIMAL(15,3)) > CAST(t1.KALAB2 AS DECIMAL(15,3))')
                ->distinct()->count('t1.VBELN'),
        ];

        // ===== Value to Packing vs Overdue by Location (match SO Report) =====
        $byLocBase = DB::table('so_yppr079_t2 as t2')
            ->join(
                'so_yppr079_t1 as t1',
                DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                '=',
                DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
            )
            ->when($location,       fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc))
            ->when($effectiveAuart, fn($q, $v)   => $q->where('t2.IV_AUART_PARAM', $v))
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) <> 0'); // outstanding only

        // Total outstanding per lokasi (semua: overdue + on time)
        $totalByLoc = (clone $byLocBase)
            ->selectRaw("
            CASE WHEN t2.IV_WERKS_PARAM = '2000' THEN 'Surabaya' ELSE 'Semarang' END AS location,
            CAST(SUM(t1.TOTPR) AS DECIMAL(18,2)) AS total_value
        ")
            ->groupBy('location')
            ->get()
            ->keyBy('location');

        // Overdue per lokasi (EDATU < today)
        $overdueByLoc = (clone $byLocBase)
            ->whereRaw("{$safeEdatuT2} < CURDATE()")
            ->selectRaw("
            CASE WHEN t2.IV_WERKS_PARAM = '2000' THEN 'Surabaya' ELSE 'Semarang' END AS location,
            CAST(SUM(t1.TOTPR) AS DECIMAL(18,2)) AS overdue_value
        ")
            ->groupBy('location')
            ->get()
            ->keyBy('location');

        // Compose final rows: on_time = total - overdue
        $locations = ['Surabaya', 'Semarang'];
        $chartData['value_by_location_status'] = collect($locations)->map(function ($loc) use ($totalByLoc, $overdueByLoc) {
            $total   = (float) ($totalByLoc[$loc]->total_value     ?? 0);
            $overdue = (float) ($overdueByLoc[$loc]->overdue_value ?? 0);
            return (object)[
                'location'      => $loc,
                'on_time_value' => max($total - $overdue, 0),
                'overdue_value' => $overdue,
            ];
        });

        // ===== Panel lainnya (tetap) =====
        $agingQuery = DB::table('so_yppr079_t3 as t3')
            ->whereIn('t3.VBELN', (clone $relevantVbelnsQuery));
        $chartData['aging_analysis'] = [
            'overdue_over_30' => (clone $agingQuery)->whereRaw("DATEDIFF(CURDATE(), {$safeEdatu}) > 30")->distinct()->count('t3.VBELN'),
            'overdue_1_30'    => (clone $agingQuery)->whereRaw("DATEDIFF(CURDATE(), {$safeEdatu}) BETWEEN 1 AND 30")->distinct()->count('t3.VBELN'),
            'due_this_week'   => (clone $agingQuery)->whereRaw("{$safeEdatu} BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->distinct()->count('t3.VBELN'),
            'on_time'         => (clone $agingQuery)->whereRaw("{$safeEdatu} > DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->distinct()->count('t3.VBELN'),
        ];

        $topOverdueBase = DB::table('so_yppr079_t2 as t2')
            ->join('so_yppr079_t1 as t1', function ($j) {
                $j->on(DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'));
            })
            // filter type via maping (sama dengan SO dashboard yg lain)
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
                })->where('m.Deskription', 'like', '%Export%')
                    ->where('m.Deskription', 'not like', '%Replace%')
                    ->where('m.Deskription', 'not like', '%Local%');
            })
            ->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc))
            ->when($auart,    fn($q, $v)   => $q->where('t2.IV_AUART_PARAM', $v))
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) <> 0')
            ->whereRaw("{$safeEdatuT2} < CURDATE()") // overdue only
            ->groupBy('t2.NAME1', 't1.WAERK')
            ->selectRaw("
            t2.NAME1,
            t1.WAERK,
            CAST(SUM(t1.TOTPR) AS DECIMAL(18,2)) AS total_value
        ")
            ->havingRaw('SUM(t1.TOTPR) > 0')
            ->orderByDesc('total_value');

        // Pecah per mata uang & batasi 5 teratas
        $chartData['top_customers_value_usd'] = (clone $topOverdueBase)
            ->where('t1.WAERK', 'USD')
            ->limit(5)
            ->get();

        $chartData['top_customers_value_idr'] = (clone $topOverdueBase)
            ->where('t1.WAERK', 'IDR')
            ->limit(5)
            ->get();

        // Due this week
        $dueThisWeekBySo = DB::table('so_yppr079_t2 as t2')
            ->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc))
            ->when($effectiveAuart, fn($q, $v) => $q->where('t2.IV_AUART_PARAM', $v))
            ->whereRaw('CAST(t2.PACKG AS DECIMAL(18,3)) <> 0')
            ->whereRaw("{$safeEdatuT2} >= ? AND {$safeEdatuT2} < ?", [$startWeek, $endWeekEx])
            ->groupBy('t2.VBELN', 't2.BSTNK', 't2.NAME1', 't2.WAERK', 't2.IV_WERKS_PARAM', 't2.IV_AUART_PARAM')
            ->selectRaw("
            t2.VBELN, t2.BSTNK, t2.NAME1, t2.WAERK,
            t2.IV_WERKS_PARAM, t2.IV_AUART_PARAM,
            CAST(SUM(t2.TOTPR2) AS DECIMAL(18,2)) AS total_value,
            DATE_FORMAT(MIN({$safeEdatuT2}), '%Y-%m-%d') AS due_date
        ")
            ->orderByDesc('total_value')
            ->limit(50)
            ->get();

        $dueThisWeekByCustomer = DB::table('so_yppr079_t2 as t2')
            ->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc))
            ->when($effectiveAuart, fn($q, $v) => $q->where('t2.IV_AUART_PARAM', $v))
            ->whereRaw('CAST(t2.PACKG AS DECIMAL(18,3)) <> 0')
            ->whereRaw("{$safeEdatuT2} >= ? AND {$safeEdatuT2} < ?", [$startWeek, $endWeekEx])
            ->groupBy('t2.NAME1', 't2.WAERK')
            ->selectRaw("t2.NAME1, t2.WAERK, CAST(SUM(t2.TOTPR2) AS DECIMAL(18,2)) AS total_value")
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

    /* ======================================================================
     * MAIN INDEX (PO vs SO tetap terpisah)
     * ====================================================================*/

    public function index(Request $request)
    {
        // --- Redirect default auart jika hanya pilih plant ---
        if ($request->filled('werks') && !$request->filled('auart')) {
            $mapping = DB::table('maping')
                ->select('IV_WERKS', 'IV_AUART', 'Deskription')
                ->where('IV_WERKS', $request->werks)
                ->orderBy('IV_AUART')
                ->get();

            $defaultType = $mapping->first(function ($item) {
                return str_contains(strtolower($item->Deskription), 'export');
            }) ?: $mapping->first();

            if ($defaultType) {
                $params = array_merge($request->query(), ['auart' => $defaultType->IV_AUART]);
                return redirect()->route('dashboard', $params);
            }
        }

        $werks    = $request->query('werks');
        $auart    = $request->query('auart');
        $location = $request->query('location'); // '2000' | '3000' | null
        $type     = $request->query('type');     // 'lokal' | 'export' | null
        $view     = $request->query('view', 'po');

        $show    = $request->filled('werks') && $request->filled('auart');
        $compact = $request->boolean('compact', $show);

        $mapping = DB::table('maping')
            ->select('IV_WERKS', 'IV_AUART', 'Deskription')
            ->orderBy('IV_WERKS')->orderBy('IV_AUART')
            ->get()
            ->groupBy('IV_WERKS')
            ->map(fn($g) => $g->unique('IV_AUART')->values());

        $rows = null;
        $selectedDescription   = '';
        $chartData             = [];
        $selectedLocationName  = 'All Locations';
        $selectedTypeName      = 'All Types';

        if ($show) {
            // ====== Halaman detail PO (report) ======
            $safeEdatu = "COALESCE(
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
        )";
            $safeEdatuInner = "COALESCE(
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2_inner.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2_inner.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
        )";

            $query = DB::table('so_yppr079_t2 as t2')
                ->leftJoin(DB::raw('(
                SELECT t2_inner.KUNNR, SUM(t1.TOTPR) AS TOTAL_TOTPR
                FROM so_yppr079_t2 AS t2_inner
                LEFT JOIN so_yppr079_t1 AS t1
                  ON TRIM(CAST(t1.VBELN AS CHAR)) = TRIM(CAST(t2_inner.VBELN AS CHAR))
                WHERE t2_inner.IV_WERKS_PARAM = ' . DB::getPdo()->quote($werks) . '
                  AND t2_inner.IV_AUART_PARAM  = ' . DB::getPdo()->quote($auart) . '
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
            // ====== Halaman DASHBOARD ======
            if ($view === 'so') {
                $chartData = $this->getSoDashboardData($request);
            } else {
                // ====== Dashboard PO ======
                $today = now()->startOfDay();
                if ($location === '2000') $selectedLocationName = 'Surabaya';
                if ($location === '3000') $selectedLocationName = 'Semarang';

                // EDATU parser
                $safeEdatu = "
            COALESCE(
                STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y'),
                STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d')
            )";

                // Basis filter sama seperti report (type & lokasi):
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

                /**
                 * ===========================
                 * KPI (Report PO logic):
                 * - SUM t1.TOTPR
                 * - TANPA filter PACKG
                 * - HANYA SO telat: EDATU < hari ini
                 * - pecah per currency dari t2.WAERK
                 * ===========================
                 */
                $kpiTotalValue = (clone $baseQuery)
                    ->leftJoin(
                        'so_yppr079_t1 as t1',
                        DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                        '=',
                        DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
                    )
                    ->selectRaw("
                        CAST(SUM(CASE WHEN t2.WAERK = 'USD' THEN t1.TOTPR ELSE 0 END) AS DECIMAL(18,2)) AS usd,
                        CAST(SUM(CASE WHEN t2.WAERK = 'IDR' THEN t1.TOTPR ELSE 0 END) AS DECIMAL(18,2)) AS idr
                    ")
                    ->first();

                $chartData['kpi'] = [
                    'total_outstanding_value_usd' => (float) ($kpiTotalValue->usd ?? 0),
                    'total_outstanding_value_idr' => (float) ($kpiTotalValue->idr ?? 0),

                    'total_outstanding_so' => (clone $baseQuery)->distinct()->count('t2.VBELN'),
                    'total_overdue_so'     => (clone $baseQuery)->whereRaw("{$safeEdatu} < ?", [$today])->distinct()->count('t2.VBELN'),
                ];

                $chartData['kpi']['overdue_rate'] =
                    $chartData['kpi']['total_outstanding_so'] > 0
                    ? ($chartData['kpi']['total_overdue_so'] / $chartData['kpi']['total_outstanding_so']) * 100
                    : 0;

                // Status ring (overdue/due this week/on time)
                $chartData['so_status'] = [
                    'overdue'       => $chartData['kpi']['total_overdue_so'],
                    'due_this_week' => (clone $baseQuery)
                        ->whereRaw("{$safeEdatu} BETWEEN ? AND ?", [$today, $today->copy()->addDays(7)])
                        ->distinct()->count('t2.VBELN'),
                    'on_time'       => (clone $baseQuery)
                        ->whereRaw("{$safeEdatu} > ?", [$today->copy()->addDays(7)])
                        ->distinct()->count('t2.VBELN'),
                ];

                // Outstanding by Location (semua outstanding, bukan hanya telat)
                $chartData['outstanding_by_location'] = (clone $baseQuery)
                    ->join(
                        'so_yppr079_t1 as t1',
                        DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                        '=',
                        DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
                    )
                    ->select(
                        DB::raw("CASE WHEN t2.IV_WERKS_PARAM = '2000' THEN 'Surabaya' ELSE 'Semarang' END as location"),
                        't2.WAERK as currency',
                        DB::raw('SUM(t1.TOTPR) as total_value'),
                        DB::raw('COUNT(DISTINCT t2.VBELN) as so_count')
                    )
                    // ->when($location, ...) tidak diperlukan lagi karena sudah ada di dalam $baseQuery
                    ->groupBy('location', 'currency')
                    ->get();

                // Top customers (tetap semua outstanding)
                $chartData['top_customers_value_usd'] = (clone $baseQuery)
                    ->join(
                        'so_yppr079_t1 as t1',
                        DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                        '=',
                        DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
                    )
                    ->select('t2.NAME1', DB::raw('SUM(t1.TOTPR) as total_value'), DB::raw('COUNT(DISTINCT t2.VBELN) as so_count'))
                    ->where('t2.WAERK', 'USD')
                    ->groupBy('t2.NAME1')
                    ->having('total_value', '>', 0)
                    ->orderByDesc('total_value')
                    ->limit(4)
                    ->get();

                $chartData['top_customers_value_idr'] = (clone $baseQuery)
                    ->join(
                        'so_yppr079_t1 as t1',
                        DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                        '=',
                        DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
                    )
                    ->select('t2.NAME1', DB::raw('SUM(t1.TOTPR) as total_value'), DB::raw('COUNT(DISTINCT t2.VBELN) as so_count'))
                    ->where('t2.WAERK', 'IDR')
                    ->groupBy('t2.NAME1')
                    ->having('total_value', '>', 0)
                    ->orderByDesc('total_value')
                    ->limit(4)
                    ->get();

                // Top customers dengan overdue terbanyak
                $chartData['top_customers_overdue'] = (clone $baseQuery)
                    ->select(
                        't2.NAME1',
                        DB::raw('COUNT(DISTINCT t2.VBELN) as overdue_count'),
                        DB::raw('GROUP_CONCAT(DISTINCT t2.IV_WERKS_PARAM ORDER BY t2.IV_WERKS_PARAM ASC SEPARATOR ", ") as locations'),
                        DB::raw("COUNT(DISTINCT CASE WHEN t2.IV_WERKS_PARAM = '3000' THEN t2.VBELN ELSE NULL END) as smg_count"),
                        DB::raw("COUNT(DISTINCT CASE WHEN t2.IV_WERKS_PARAM = '2000' THEN t2.VBELN ELSE NULL END) as sby_count")
                    )
                    ->whereRaw("{$safeEdatu} < CURDATE()")
                    ->groupBy('t2.NAME1')
                    ->orderByDesc('overdue_count')
                    ->limit(4)
                    ->get();

                // Performance analysis
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
                    );

                $typesToFilter = null;
                if ($type === 'lokal' || $type === 'export') {
                    $cloneForFilter = (clone $baseQuery)->select('t2.IV_AUART_PARAM', 't2.IV_WERKS_PARAM')->distinct();
                    $typesToFilter = $cloneForFilter->get()->map(fn($item) => $item->IV_AUART_PARAM . '-' . $item->IV_WERKS_PARAM)->toArray();
                }
                if ($typesToFilter !== null) {
                    $performanceQueryBase->whereIn(DB::raw("CONCAT(m.IV_AUART, '-', m.IV_WERKS)"), $typesToFilter);
                }

                $safeEdatuPerf = $safeEdatu;
                $performanceQuery = $performanceQueryBase->select(
                    'm.Deskription',
                    'm.IV_WERKS',
                    DB::raw('COUNT(DISTINCT t2.VBELN) as total_so'),
                    // value overdue by currency — T2 only
                    DB::raw("SUM(CASE WHEN t2.WAERK = 'IDR' AND {$safeEdatuPerf} < CURDATE() THEN CAST(t2.TOTPR2 AS DECIMAL(18,2)) ELSE 0 END) as total_value_idr"),
                    DB::raw("SUM(CASE WHEN t2.WAERK = 'USD' AND {$safeEdatuPerf} < CURDATE() THEN CAST(t2.TOTPR2 AS DECIMAL(18,2)) ELSE 0 END) as total_value_usd"),
                    DB::raw("COUNT(DISTINCT CASE WHEN {$safeEdatuPerf} < CURDATE() THEN t2.VBELN ELSE NULL END) as overdue_so_count"),
                    DB::raw("COUNT(DISTINCT CASE WHEN DATEDIFF(CURDATE(), {$safeEdatuPerf}) BETWEEN 1 AND 30 THEN t2.VBELN ELSE NULL END) as overdue_1_30"),
                    DB::raw("COUNT(DISTINCT CASE WHEN DATEDIFF(CURDATE(), {$safeEdatuPerf}) BETWEEN 31 AND 60 THEN t2.VBELN ELSE NULL END) as overdue_31_60"),
                    DB::raw("COUNT(DISTINCT CASE WHEN DATEDIFF(CURDATE(), {$safeEdatuPerf}) BETWEEN 61 AND 90 THEN t2.VBELN ELSE NULL END) as overdue_61_90"),
                    DB::raw("COUNT(DISTINCT CASE WHEN DATEDIFF(CURDATE(), {$safeEdatuPerf}) > 90 THEN t2.VBELN ELSE NULL END) as overdue_over_90")
                )
                    ->when($location, fn($q, $loc) => $q->where('t2.IV_WERKS_PARAM', $loc))
                    ->groupBy('m.IV_WERKS', 'm.Deskription')
                    ->orderBy('m.IV_WERKS')->orderBy('m.Deskription')
                    ->get();

                $chartData['so_performance_analysis'] = $performanceQuery;

                // Small qty by customer
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
                    ->groupBy('t2.NAME1', 't2.IV_WERKS_PARAM')
                    ->orderBy('t2.NAME1')
                    ->get();

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

    /* ======================================================================
     * API lain (unchanged): apiT2, apiT3, search, apiSmallQtyDetails
     * ====================================================================*/

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
                if ($qtyPo > 0) $itemPercentages[] = ($outstanding / $qtyPo) * 100;
            }
            $row->ShortagePercentage = count($itemPercentages) ? array_sum($itemPercentages) / count($itemPercentages) : 0;
        }

        $sortedRows = $rows->sortBy('Overdue')->values();
        return response()->json(['ok' => true, 'data' => $sortedRows]);
    }

    public function apiT3(Request $req)
    {
        $vbeln = trim((string) $req->query('vbeln'));
        if ($vbeln === '') return response()->json(['ok' => false, 'error' => 'vbeln missing'], 400);

        $werks = $req->query('werks');
        $auart = $req->query('auart');

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
            ->where(function ($q) use ($vbeln) {
                $q->where('t1.VBELN', $vbeln)
                    ->orWhereRaw('TRIM(CAST(t1.VBELN AS CHAR)) = TRIM(?)', [$vbeln])
                    ->orWhereRaw('CAST(TRIM(t1.VBELN) AS UNSIGNED) = CAST(TRIM(?) AS UNSIGNED)', [$vbeln]);
            })
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
        $term = $request->input('term');

        $so_info = DB::table('so_yppr079_t2')
            ->where(function ($query) use ($term) {
                $query->whereRaw('TRIM(CAST(VBELN AS CHAR)) = ?', [$term])
                    ->orWhereRaw('TRIM(CAST(BSTNK AS CHAR)) = ?', [$term]);
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
                'search_term'     => $term,
            ];
            return redirect()->route('dashboard', $params);
        }

        return back()->withErrors(['term' => 'Nomor PO atau SO "' . $term . '" tidak ditemukan.'])->withInput();
    }

    public function apiSmallQtyDetails(Request $request)
    {
        $request->validate([
            'customerName' => 'required|string',
            'locationName' => 'required|string|in:Semarang,Surabaya'
        ]);

        $customerName = $request->query('customerName');
        $locationName = $request->query('locationName');
        $type         = $request->query('type');

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
