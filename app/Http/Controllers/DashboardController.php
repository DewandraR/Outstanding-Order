<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;

class DashboardController extends Controller
{
    public function redirector(Request $request)
    {
        try {
            $raw  = (string) $request->input('payload', '');
            $data = json_decode($raw, true);
            if (!is_array($data) || empty($data)) {
                throw new \RuntimeException('Invalid payload data.');
            }

            $route = $data['redirect_to'] ?? 'dashboard';
            unset($data['redirect_to']);

            // ⬇️ TAMBAH key 'highlight_posnr' dan 'auto'
            $whitelist = [
                'view',
                'werks',
                'auart',
                'compact',
                'highlight_kunnr',
                'highlight_vbeln',
                'highlight_posnr', // <<-- TAMBAH INI
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

            // samakan nama param: auto_expand -> auto (dipakai di SO Report)
            if (isset($clean['auto_expand']) && !isset($clean['auto'])) {
                $clean['auto'] = (string) (int) !!$clean['auto_expand'];
                unset($clean['auto_expand']);
            }

            // pastikan POSNR dikirim sebagai string (biar nol di depan tidak jadi masalah)
            if (isset($clean['highlight_posnr'])) {
                $clean['highlight_posnr'] = (string) $clean['highlight_posnr'];
            }

            $allowed = ['dashboard', 'so.index'];
            if (!in_array($route, $allowed, true)) $route = 'dashboard';

            $q = Crypt::encrypt($clean);

            return $route === 'so.index'
                ? redirect()->route('so.index',  ['q' => $q])
                : redirect()->route('dashboard', ['q' => $q]);
        } catch (\Throwable $e) {
            return redirect()->route('dashboard')
                ->withErrors('Gagal memproses link. Data tidak valid.');
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
        $today   = Carbon::today();
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
        $window   = (int) $request->query('window', 7);
        $location = $request->query('location'); // '2000' | '3000' | null
        $type     = $request->query('type');     // 'lokal' | 'export' | null
        $auart    = $request->query('auart');    // optional

        $today     = now()->startOfDay();
        $startWeek = now()->startOfWeek(\Carbon\Carbon::MONDAY)->startOfDay(); // inclusive
        $endWeekEx = (clone $startWeek)->addWeek();                             // exclusive

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
         *       namun “outstanding” ditentukan oleh EXISTS item T1 PACKG > 0
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
         * ===== KPI “Outs Value Packing USD/IDR” — SAMA DENGAN SO REPORT
         *       SUM(TOTPR2) untuk item outstanding yang OVERDUE (EDATU < today), per currency
         */
        $overduePackingAgg = (clone $packItemsBase)
            ->whereRaw("{$safeEdatuT2} < CURDATE()")
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
            // >>> KPI Outs Value Packing (overdue TOTPR2) — match SO Report
            'total_outstanding_value_usd' => $kpiPackingUsd,
            'total_outstanding_value_idr' => $kpiPackingIdr,

            'total_outstanding_so'        => $totalOutstandingSo,
            'total_overdue_so'            => $totalOverdueSo,
            'overdue_rate'                => $totalOutstandingSo > 0 ? ($totalOverdueSo / $totalOutstandingSo) * 100 : 0,

            // minggu berjalan (TOTPR2)
            'value_to_ship_this_week_usd' => $valueToShipUsd,
            'value_to_ship_this_week_idr' => $valueToShipIdr,

            'potential_bottlenecks'       => (clone $potentialBottlenecksQuery)->distinct()->count('t1.VBELN'),
        ];

        /**
         * ===== Value to Packing vs Overdue by Location — pakai TOTPR2
         *       Total = SUM(TOTPR2) outstanding; Overdue = SUM(TOTPR2) overdue
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
         *       Pakai TOTPR2 item outstanding yang overdue, agregasi per KUNNR.
         */
        $topOverdueBase = (clone $packItemsBase)
            ->whereRaw("{$safeEdatuT2} < CURDATE()")
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
        $decryptedParams = [];
        if ($request->has('q')) {
            try {
                // Dekripsi parameter 'q' dari URL
                $decryptedParams = Crypt::decrypt($request->query('q'));
                if (!is_array($decryptedParams)) {
                    $decryptedParams = [];
                }
            } catch (DecryptException $e) {
                // Jika dekripsi gagal (URL diubah manual/tidak valid), kembalikan ke dashboard default.
                return redirect()->route('dashboard')->withErrors('Link tidak valid atau telah kadaluwarsa.');
            }
        }

        // >>> PENTING: merge hasil dekripsi ke $request agar semua helper yg memakai $request->query(...) mendapatkan nilai yang benar
        if (!empty($decryptedParams)) {
            $request->merge($decryptedParams);
        }

        // Ambil nilai dari request (sudah termasuk hasil merge di atas)
        $werks    = $request->query('werks');                         // '2000' | '3000' | null
        $auart    = $request->query('auart');                         // kode AUART | null
        $location = $request->query('location');                      // '2000' | '3000' | null
        $type     = $request->query('type');                          // 'lokal' | 'export' | null
        $view     = $request->query('view', 'po');                    // 'po' | 'so'

        // --- Redirect default auart jika hanya pilih plant (link lama tanpa enkripsi dari sidebar)
        if ($request->filled('werks') && !$request->filled('auart') && !$request->has('q')) {
            $mapping = DB::table('maping')
                ->select('IV_WERKS', 'IV_AUART', 'Deskription')
                ->where('IV_WERKS', $request->werks)
                ->orderBy('IV_AUART')
                ->get();

            $defaultType = $mapping->first(function ($item) {
                return str_contains(strtolower($item->Deskription), 'export');
            }) ?: $mapping->first();

            if ($defaultType) {
                // Enkripsi parameter saat redirect
                $params = ['werks' => $defaultType->IV_WERKS, 'auart' => $defaultType->IV_AUART, 'compact' => 1];
                return redirect()->route('dashboard', ['q' => Crypt::encrypt($params)]);
            }
        }

        $show    = filled($werks) && filled($auart);
        $compact = $request->boolean('compact', $show);

        // Mapping untuk sidebar/label
        $mapping = DB::table('maping')
            ->select('IV_WERKS', 'IV_AUART', 'Deskription')
            ->orderBy('IV_WERKS')->orderBy('IV_AUART')
            ->get()
            ->groupBy('IV_WERKS')
            ->map(fn($g) => $g->unique('IV_AUART')->values());

        $rows = null;
        $selectedDescription  = '';
        $chartData            = [];
        $selectedLocationName = 'All Locations';
        $selectedTypeName     = 'All Types';
        $availableAuart       = collect();

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
                // Gunakan filter hasil merge ($location, $type, dll) di dalam $request
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

                // KPI (Report PO logic) — pecah currency dari t2.WAERK
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
                    ->groupBy('location', 'currency')
                    ->get();

                // Top customers (semua outstanding), pisah USD/IDR
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
                    DB::raw("SUM(CASE WHEN t2.WAERK = 'IDR' AND {$safeEdatuPerf} < CURDATE() THEN CAST(t1.TOTPR AS DECIMAL(18,2)) ELSE 0 END) as total_value_idr"),
                    DB::raw("SUM(CASE WHEN t2.WAERK = 'USD' AND {$safeEdatuPerf} < CURDATE() THEN CAST(t1.TOTPR AS DECIMAL(18,2)) ELSE 0 END) as total_value_usd"),
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
            'availableAuart'       => $availableAuart,
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
            // Gabungkan semua parameter yang dibutuhkan ke dalam satu array
            $params = [
                'werks'           => $so_info->IV_WERKS_PARAM,
                'auart'           => $so_info->IV_AUART_PARAM,
                'compact'         => 1,
                'highlight_kunnr' => $so_info->KUNNR,
                'highlight_vbeln' => $so_info->VBELN,
                'search_term'     => $term, // Bisa disimpan untuk menampilkan di UI jika perlu
            ];

            // Enkripsi seluruh array menjadi satu string
            $encryptedPayload = Crypt::encrypt($params);

            // Redirect ke halaman report dengan parameter terenkripsi
            return redirect()->route('dashboard', ['q' => $encryptedPayload]);
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
