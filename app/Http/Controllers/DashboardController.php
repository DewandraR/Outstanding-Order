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
        $location = $request->query('location');      // '2000' | '3000' | null
        $type     = $request->query('type');          // 'lokal' | 'export' | null

        // 3) Mapping untuk sidebar/label
        $mapping = DB::table('maping')
            ->select('IV_WERKS', 'IV_AUART', 'Deskription')
            ->orderBy('IV_WERKS')->orderBy('IV_AUART')
            ->get()
            ->groupBy('IV_WERKS')
            ->map(fn($g) => $g->unique('IV_AUART')->values());

        // 4) Variabel dashboard
        $chartData            = [];
        $selectedLocationName = 'All Locations';
        $selectedTypeName     = 'All Types';

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

        // KPI (pecah currency dari t2.WAERK, nilai dari T1.TOTPR)
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

        // Outstanding by Location
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

        // Top customers (USD)
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

        // Top customers (IDR)
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

        // Top customers overdue
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

        // 5) Return view PO dashboard
        return view('dashboard', [
            'mapping'              => $mapping,
            'chartData'            => $chartData,
            'selectedLocation'     => $location,
            'selectedLocationName' => $selectedLocationName,
            'selectedType'         => $type,
            'selectedTypeName'     => $selectedTypeName,
            'view'                 => 'po', // fix ke 'po'
        ]);
    }

    /* ================== API yang tetap untuk PO / PO Report ================== */

    public function apiPoOverdueDetails(Request $request)
    {
        $request->validate([
            'werks'  => 'required|string',
            'auart'  => 'required|string',
            'bucket' => 'required|string|in:1_30,31_60,61_90,gt_90',
        ]);

        $werks  = $request->query('werks');
        $auart  = $request->query('auart');
        $bucket = $request->query('bucket');

        $safeEdatu = "COALESCE(
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
        )";

        $q = DB::table('so_yppr079_t2 as t2')
            ->selectRaw("
                TRIM(t2.BSTNK)                              AS PO,
                TRIM(t2.VBELN)                              AS SO,
                DATE_FORMAT({$safeEdatu}, '%d-%m-%Y')       AS EDATU,  
                DATEDIFF(CURDATE(), {$safeEdatu})           AS OVERDUE_DAYS
            ")
            ->where('t2.IV_WERKS_PARAM', $werks)
            ->where('t2.IV_AUART_PARAM', $auart);

        switch ($bucket) {
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

        $rows = $q->orderByDesc('OVERDUE_DAYS')
            ->orderBy('t2.VBELN')
            ->orderBy('t2.BSTNK')
            ->limit(2000)
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
        if ($vbeln === '') {
            return response()->json(['ok' => false, 'error' => 'vbeln missing'], 400);
        }

        $werks = $req->query('werks');
        $auart = $req->query('auart');

        $rows = DB::table('so_yppr079_t1 as t1')
            ->leftJoin(
                'so_yppr079_t2 as t2',
                DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'),
                '=',
                DB::raw('TRIM(CAST(t1.VBELN AS CHAR))')
            )
            ->select(
                't1.id', // << PENTING untuk export
                DB::raw('TRIM(CAST(t1.VBELN AS CHAR)) as VBELN'),
                DB::raw("TRIM(LEADING '0' FROM t1.POSNR) as POSNR"),
                DB::raw("CASE WHEN t1.MATNR REGEXP '^[0-9]+$' THEN TRIM(LEADING '0' FROM t1.MATNR) ELSE t1.MATNR END as MATNR"),
                't1.MAKTX',
                't1.KWMENG',
                't1.QTY_GI',
                't1.QTY_BALANCE2',
                't1.KALAB',   // WHFG
                't1.KALAB2',  // FG  << supaya kolom FG di Blade terisi
                't1.NETPR',
                't1.WAERK'
            )
            ->whereRaw('TRIM(CAST(t1.VBELN AS CHAR)) = TRIM(?)', [$vbeln])
            ->when($werks, fn($q) => $q->where('t1.IV_WERKS_PARAM', $werks))
            ->when($auart, fn($q) => $q->where('t1.IV_AUART_PARAM', $auart))
            ->orderByRaw('CAST(t1.POSNR AS UNSIGNED)')
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
            $encryptedPayload = Crypt::encrypt($params);
            return redirect()->route('po.report', ['q' => $encryptedPayload]);
        }

        return back()->withErrors(['term' => 'Nomor PO atau SO "' . $term . '" tidak ditemukan.'])->withInput();
    }

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

            // Daftar parameter yang diizinkan (pastikan 'highlight_posnr' dan 'auto_expand' ada)
            $whitelist = [
                'view',
                'werks',
                'auart',
                'compact',
                'highlight_kunnr',
                'highlight_vbeln',
                'highlight_posnr', // <<-- DIPERLUKAN UNTUK ITEM REMARK
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

            // pastikan POSNR dikirim sebagai string (agar leading zero tidak hilang)
            if (isset($clean['highlight_posnr'])) {
                $clean['highlight_posnr'] = (string) $clean['highlight_posnr'];
            }

            // FIX UTAMA: Tambahkan 'so.index' ke daftar yang diizinkan DAN tambahkan logika redirect ke so.index
            $allowed = ['dashboard', 'po.report', 'so.index'];
            if (!in_array($route, $allowed, true)) $route = 'dashboard';

            $q = Crypt::encrypt($clean);

            // Arahkan ke route yang benar: so.index, po.report, atau dashboard
            return $route === 'so.index'
                ? redirect()->route('so.index',  ['q' => $q])
                : ($route === 'po.report'
                    ? redirect()->route('po.report', ['q' => $q])
                    : redirect()->route('dashboard', ['q' => $q]));
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
    public function apiPoOutsByCustomer(Request $request)
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
            ->when($location, fn($qq, $v) => $qq->where('t2.IV_WERKS_PARAM', $v))
            ->when($auart,    fn($qq, $v) => $qq->where('t2.IV_AUART_PARAM', $v));

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
        } elseif ($type === 'export') {
            $q->whereIn(DB::raw('(t2.IV_AUART_PARAM, t2.IV_WERKS_PARAM)'), function ($sub) {
                $sub->select('IV_AUART', 'IV_WERKS')->from('maping')
                    ->where('Deskription', 'like', '%Export%')
                    ->where('Deskription', 'not like', '%Replace%')
                    ->where('Deskription', 'not like', '%Local%');
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
    // API 2: Detail PO Status (Doughnut Chart Click)
    public function apiPoStatusDetails(Request $request)
    {
        // Route ini dipanggil oleh donut chart, fungsinya sama persis dengan apiSoStatusDetails
        // di code lama Anda, jadi kita alihkan saja ke apiSoStatusDetails
        return $this->apiSoStatusDetails($request);
    }


    // API 3: Detail Small Qty (≤5) Outstanding Items by Customer
    // DI DALAM CLASS DashboardController

    public function apiSmallQtyDetails(Request $request)
    {
        $request->validate([
            'customerName' => 'required|string',
            // [KOREKSI 1]: Di Blade JS sebelumnya, Anda mengirim 'locationCode', tapi jika Anda 
            // kembali ke key 'locationName' dengan nilai 'Semarang'/'Surabaya' seperti di template,
            // kita gunakan validasi ini:
            'locationName' => 'required|string|in:Semarang,Surabaya',
            'type'         => 'nullable|string|in:lokal,export'
        ]);

        $customerName = $request->query('customerName');
        $locationName = $request->query('locationName');
        $type         = $request->query('type');

        // Konversi nama lokasi menjadi kode pabrik (WERKS)
        $werks = ($locationName === 'Semarang') ? '3000' : '2000';

        $query = DB::table('so_yppr079_t1 as t1')
            ->join('so_yppr079_t2 as t2', DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'))
            // Gunakan LEFT JOIN untuk maping agar tidak menghilangkan data jika mapping tidak ditemukan
            ->leftJoin('maping as m', function ($join) {
                $join->on('t2.IV_AUART_PARAM', '=', 'm.IV_AUART')
                    ->on('t2.IV_WERKS_PARAM', '=', 'm.IV_WERKS');
            })
            ->where('t2.NAME1', $customerName)
            ->where('t2.IV_WERKS_PARAM', $werks)
            ->where('t1.QTY_BALANCE2', '>', 0)
            ->where('t1.QTY_BALANCE2', '<=', 5);

        // KOREKSI LOGIKA FILTER TYPE AGAR SAMA DENGAN index()
        if ($type === 'lokal') {
            $query->where(function ($q) {
                $q->where('m.Deskription', 'like', '%Local%')
                    // Logika ini mengikuti struktur filter umum (termasuk replace/lokal jika ada)
                    ->orWhere('m.Deskription', 'like', '%Replace%');
            });
        } elseif ($type === 'export') {
            $query->where('m.Deskription', 'like', '%Export%')
                ->where('m.Deskription', 'not like', '%Replace%')
                ->where('m.Deskription', 'not like', '%Local%');
        }

        // [KOREKSI 2]: Tambahkan QTY_GI (jika ini kolom yang benar) dan pastikan urutan select
        $items = $query->select(
            't2.VBELN',
            't2.BSTNK',
            DB::raw("TRIM(LEADING '0' FROM t1.POSNR) as POSNR"),
            't1.MAKTX',
            't1.KWMENG',
            't1.QTY_GI',
            't1.QTY_BALANCE2'
        )
            ->orderBy('t2.VBELN', 'asc')
            ->orderByRaw('LPAD(TRIM(CAST(t1.POSNR AS CHAR)), 6, "0")')
            ->get();

        return response()->json(['ok' => true, 'data' => $items]);
    }

    // ... (Fungsi apiSoStatusDetails dan apiSoUrgencyDetails (yang lama) di bawahnya) ...

    // apiSoStatusDetails di code lama Anda:

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
            'type'         => 'nullable|string|in:lokal,export',
        ]);

        $customerName = $validated['customerName'];
        $locationName = $validated['locationName'];
        $type         = $validated['type'] ?? null;
        $werks        = $locationName === 'Semarang' ? '3000' : '2000';

        // Query sama dengan apiSmallQtyDetails()
        $q = DB::table('so_yppr079_t1 as t1')
            ->join('so_yppr079_t2 as t2', DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'))
            ->leftJoin('maping as m', function ($j) {
                $j->on('t2.IV_AUART_PARAM', '=', 'm.IV_AUART')
                    ->on('t2.IV_WERKS_PARAM', '=', 'm.IV_WERKS');
            })
            ->where('t2.NAME1', $customerName)
            ->where('t2.IV_WERKS_PARAM', $werks)
            ->where('t1.QTY_BALANCE2', '>', 0)
            ->where('t1.QTY_BALANCE2', '<=', 5);

        if ($type === 'lokal') {
            $q->where(function ($qq) {
                $qq->where('m.Deskription', 'like', '%Local%')
                    ->orWhere('m.Deskription', 'like', '%Replace%');
            });
        } elseif ($type === 'export') {
            $q->where('m.Deskription', 'like', '%Export%')
                ->where('m.Deskription', 'not like', '%Replace%')
                ->where('m.Deskription', 'not like', '%Local%');
        }

        $items = $q->select(
            't2.BSTNK as PO',
            't2.VBELN as SO',
            DB::raw("TRIM(LEADING '0' FROM t1.POSNR) as POSNR"),
            't1.MAKTX',
            't1.KWMENG',
            't1.QTY_GI',
            't1.QTY_BALANCE2'
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
            'type'         => $type,
            'generatedAt'  => now()->format('d-m-Y'), // <-- tadinya d-m-Y H:i
        ];

        // Jika dompdf tersedia → stream PDF; kalau tidak, render HTML biasa
        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('po_report.small-qty-pdf', [
                'items' => $items,
                'meta' => $meta,
                'totals' => $totals,
            ])->setPaper('a4', 'portrait');

            $filename = 'SmallQty_' . $locationName . '_' . Str::slug($customerName) . '.pdf';
            return $pdf->stream($filename);   // pakai ->download($filename) jika mau auto-download
        }

        return view('po_report.small-qty-pdf', [
            'items' => $items,
            'meta' => $meta,
            'totals' => $totals,
        ]);
    }
}
