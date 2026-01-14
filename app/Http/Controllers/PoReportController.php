<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Exports\PoItemsExport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class PoReportController extends Controller
{

    private int $exportTokenTtlMinutes = 15;

    private function packToToken(array $payload): string
    {
        $t = (string) \Illuminate\Support\Str::ulid();
        Cache::put("poexp:$t", [
            'uid'  => \Illuminate\Support\Facades\Auth::id(),
            'data' => $payload,
        ], now()->addMinutes($this->exportTokenTtlMinutes));
        return $t;
    }

    private function unpackFromToken(?string $t): array
    {
        abort_unless($t, 400, 'Missing token');
        $bag = Cache::get("poexp:$t");                        // multi-use: get (bukan pull)
        abort_if(!$bag, 410, 'Token expired or not found');
        abort_if(($bag['uid'] ?? null) !== Auth::id(), 403, 'Token owner mismatch');
        // refresh TTL biar tombol Download di viewer tetap hidup
        Cache::put("poexp:$t", $bag, now()->addMinutes($this->exportTokenTtlMinutes));
        return (array) ($bag['data'] ?? []);
    }

    private function resolveLocationName(string $werks): string
    {
        return [
            '2000' => 'Surabaya',
            '3000' => 'Semarang',
        ][$werks] ?? $werks;
    }

    private function buildFileName(string $base, string $ext): string
    {
        return sprintf('%s_%s.%s', $base, Carbon::now()->format('Ymd_His'), $ext);
    }

    private function parsePoSearch(string $q): array
    {
        $q = strtoupper(trim($q));

        $res = [
            'kunnr' => null,
            'vbeln' => null,
            'bstnk' => null,
            'posnr' => null,
        ];

        // Pisah berdasarkan spasi, koma, titik koma, slash
        $tokens = preg_split('/[\s,;\/]+/', $q);

        $mode = null;
        foreach ($tokens as $t) {
            if ($t === '') continue;

            // Keyword penanda
            if (in_array($t, ['CUST', 'CUSTOMER'])) {
                $mode = 'kunnr';
                continue;
            }
            if ($t === 'SO') {
                $mode = 'vbeln';
                continue;
            }
            if ($t === 'PO') {
                $mode = 'bstnk';
                continue;
            }
            if (in_array($t, ['ITEM', 'IT'])) {
                $mode = 'posnr';
                continue;
            }

            $digits = preg_replace('/\D+/', '', $t);
            if ($digits === '') {
                // non-digit, mungkin PO alfanumerik → kalau mode bstnk, simpan apa adanya
                if ($mode === 'bstnk' && !$res['bstnk']) {
                    $res['bstnk'] = $t;
                }
                continue;
            }

            switch ($mode) {
                case 'kunnr':
                    $res['kunnr'] = str_pad($digits, 10, '0', STR_PAD_LEFT);
                    break;
                case 'vbeln':
                    $res['vbeln'] = str_pad($digits, 10, '0', STR_PAD_LEFT);
                    break;
                case 'bstnk':
                    // PO sering alfanumerik → jangan dipaksa padding digit
                    $res['bstnk'] = $t;
                    break;
                case 'posnr':
                    $res['posnr'] = str_pad($digits, 6, '0', STR_PAD_LEFT);
                    break;
                default:
                    // fallback: kalau cuma angka tunggal
                    if (!$res['vbeln'] && strlen($digits) >= 8) {
                        $res['vbeln'] = str_pad($digits, 10, '0', STR_PAD_LEFT);
                    } elseif (!$res['posnr'] && strlen($digits) <= 6) {
                        $res['posnr'] = str_pad($digits, 6, '0', STR_PAD_LEFT);
                    }
            }
        }

        return [
            $res['kunnr'],
            $res['vbeln'],
            $res['bstnk'],
            $res['posnr'],
        ];
    }

    private function resolveAuartListForContext(?string $auart, ?string $werks = null): array
    {
        $auart = strtoupper(trim((string) $auart));
        if ($auart === '') return [];

        $q = DB::table('maping')->select('IV_WERKS', 'IV_AUART', 'Deskription');
        if ($werks) $q->where('IV_WERKS', $werks);
        $m = $q->get();

        $export = $m->filter(function ($i) {
            $d = strtolower((string) $i->Deskription);
            return str_contains($d, 'export') && !str_contains($d, 'local') && !str_contains($d, 'replace');
        })->pluck('IV_AUART')->unique()->values()->all();

        $replace = $m->filter(function ($i) {
            return str_contains(strtolower((string) $i->Deskription), 'replace');
        })->pluck('IV_AUART')->unique()->values()->all();

        if (in_array($auart, $export, true) && !in_array($auart, $replace, true)) {
            return array_values(array_unique(array_merge($export, $replace)));
        }

        return [$auart];
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
    private function applyAuartT2($query, array $auarts, string $t1Table = 'so_yppr079_t1')
    {
        $auarts = array_values(array_filter($auarts));
        if (empty($auarts)) return $query;

        return $query->where(function ($q) use ($auarts, $t1Table) {
            $q->whereIn('t2.IV_AUART_PARAM', $auarts)
            ->orWhereExists(function ($ex) use ($auarts, $t1Table) {
                $ex->select(DB::raw(1))
                    ->from("$t1Table as t1x")
                    ->whereColumn('t1x.VBELN', 't2.VBELN')
                    ->where(function ($w) use ($auarts) {
                        $w->whereIn('t1x.IV_AUART_PARAM', $auarts)
                        ->orWhereIn('t1x.AUART2', $auarts);
                    });
            });
        });
    }


    public function apiItemSearch(Request $request)
    {
        $request->validate([
            'q'     => 'required|string',
            'werks' => 'nullable|string',
            'auart' => 'nullable|string',
        ]);

        $keyword = trim((string) $request->query('q', ''));
        $werks   = $request->query('werks');
        $auart   = $request->query('auart');

        $mode = strtolower((string) $request->query('mode', session('po_mode', 'outstanding')));
        if (!in_array($mode, ['outstanding','complete'], true)) $mode = 'outstanding';
        session(['po_mode' => $mode]);

        $t1Table = $mode === 'complete' ? 'so_yppr079_t1_comp' : 'so_yppr079_t1';
        $t2Table = $mode === 'complete' ? 'so_yppr079_t2_comp' : 'so_yppr079_t2';

        $qtyOp = $mode === 'complete' ? '=' : '>';

        if ($keyword === '') {
            return response()->json([
                'ok'      => false,
                'message' => 'Kata kunci kosong.',
            ], 400);
        }

        // --- Samakan logika AUART list (Export + Replace) dengan index() ---
        $auartList = [];
        if ($auart) {
            $rawMapping = DB::table('maping')
                ->select('IV_AUART', 'Deskription')
                ->get();

            $exportAuartCodes = $rawMapping
                ->filter(
                    fn($i) =>
                    Str::contains(strtolower((string) $i->Deskription), 'export') &&
                        !Str::contains(strtolower((string) $i->Deskription), 'local') &&
                        !Str::contains(strtolower((string) $i->Deskription), 'replace')
                )
                ->pluck('IV_AUART')->unique()->toArray();

            $replaceAuartCodes = $rawMapping
                ->filter(
                    fn($i) =>
                    Str::contains(strtolower((string) $i->Deskription), 'replace')
                )
                ->pluck('IV_AUART')->unique()->toArray();

            $auartList = [$auart];
            if (in_array($auart, $exportAuartCodes, true) && !in_array($auart, $replaceAuartCodes, true)) {
                $auartList = array_merge($exportAuartCodes, $replaceAuartCodes);
            }
            $auartList = array_unique(array_filter($auartList));
        }

        // --- Query dasar: item outstanding saja ---
        $q = DB::table("$t1Table as t1")
            ->join(
                "$t2Table as t2",
                DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                '=',
                DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
            )
            ->select(
                't1.VBELN',
                DB::raw("LPAD(TRIM(CAST(t1.POSNR AS CHAR)), 6, '0') AS POSNR_DB"),
                't2.KUNNR',
                't2.NAME1 as CUSTOMER_NAME',
                't1.MATNR',
                't1.MAKTX'
            )
            ->whereRaw("CAST(t1.QTY_BALANCE2 AS DECIMAL(18,3)) {$qtyOp} 0");

        if ($werks) { $q->where('t2.IV_WERKS_PARAM', $werks); }

        if (!empty($auartList)) {
            $this->applyAuartT1($q, 't1', $auartList);
        } elseif ($auart) {
            // fallback kalau mapping gagal
            $q->where('t1.IV_AUART_PARAM', $auart);
        }

        // --- Deteksi: ini Material FG atau Desc FG? ---
        $onlyDigitsAndDots = preg_match('/^[0-9.]+$/', $keyword) === 1;

        if ($onlyDigitsAndDots) {
            // Anggap Material FG -> exact (abaikan titik)
            $digits = preg_replace('/\D+/', '', $keyword);
            if ($digits !== '') {
                $q->whereRaw("REPLACE(t1.MATNR, '.', '') = ?", [$digits]);
            }
        } else {
            // Desc FG → boleh sebagian, case-insensitive
            $upper = Str::upper($keyword);
            $q->whereRaw('UPPER(t1.MAKTX) LIKE ?', ['%' . $upper . '%']);
        }

        // --- Ambil SEMUA match (dibatasi biar gak kebanyakan) ---
        $rows = $q
            ->orderBy('t2.KUNNR')
            ->orderBy('t1.VBELN')
            ->orderBy('t1.POSNR')
            ->limit(200)
            ->get();

        if ($rows->isEmpty()) {
            return response()->json([
                'ok'                   => true,
                'data'                 => null,
                'matches_for_customer' => [],
            ]);
        }

        // Anchor pertama: dipakai buat loncat ke posisi awal
        $first = $rows->first();

        // Semua match utk customer yang sama
        $matchesForCustomer = $rows
            ->where('KUNNR', $first->KUNNR)
            ->values()
            ->map(function ($r) {
                return [
                    'VBELN'    => $r->VBELN,
                    'POSNR_DB' => $r->POSNR_DB,
                    'MATNR'    => $r->MATNR,
                    'MAKTX'    => $r->MAKTX,
                ];
            })
            ->all();

        return response()->json([
            'ok'                   => true,
            'data'                 => $first,
            'matches_for_customer' => $matchesForCustomer,
        ]);
    }


    /** Halaman report (tabel) */
    public function index(Request $request)
    {
        // 1) Terima & merge parameter terenkripsi (q) bila ada
        if ($request->has('q')) {
            try {
                $data = Crypt::decrypt($request->query('q'));
                if (is_array($data)) {
                    $request->merge($data);
                }
            } catch (DecryptException $e) {
                return redirect()->route('dashboard')->withErrors('Link Report tidak valid.');
            }
        }

        $mode = strtolower((string) $request->query('mode', session('po_mode', 'outstanding')));
        if (!in_array($mode, ['outstanding','complete'], true)) $mode = 'outstanding';
        session(['po_mode' => $mode]);


        $t1Table = $mode === 'complete' ? 'so_yppr079_t1_comp' : 'so_yppr079_t1';
        $t2Table = $mode === 'complete' ? 'so_yppr079_t2_comp' : 'so_yppr079_t2';
        // kalau memang ada t3 table di project kamu:
        $t3Table = $mode === 'complete' ? 'so_yppr079_t3_comp' : 'so_yppr079_t3';

        $showCharts = $mode === 'outstanding';
        $qtyOp = $mode === 'complete' ? '=' : '>';
        // 2) Ambil filter utama
        $werks   = $request->query('werks');                 // '2000' | '3000'
        $auart   = $request->query('auart');                 // kode AUART
        $compact = $request->boolean('compact', true);
        $show    = filled($werks) && filled($auart);

        $search = trim((string) $request->query('search', ''));

        // Bisa juga datang dari query langsung (mis: dari global search)
        $highlightKunnr = $request->query('highlight_kunnr');
        $highlightVbeln = $request->query('highlight_vbeln');
        $highlightBstnk = $request->query('highlight_bstnk');
        $highlightPosnr = $request->query('highlight_posnr');

        // Kalau user isi search bebas dan highlight belum diisi → parse
        if ($search !== '' && !$highlightKunnr && !$highlightVbeln && !$highlightBstnk && !$highlightPosnr) {
            [$highlightKunnr, $highlightVbeln, $highlightBstnk, $highlightPosnr] = $this->parsePoSearch($search);
        }

        // Flag untuk menyalakan autoOpenFromSearch di JS
        $needAutoExpand = (bool) ($highlightKunnr || $highlightVbeln || $highlightBstnk || $highlightPosnr);

        // 3) Mapping AUART mentah
        $rawMapping = DB::table('maping')
            ->select('IV_WERKS', 'IV_AUART', 'Deskription')
            ->orderBy('IV_WERKS')->orderBy('IV_AUART')
            ->get();

        // 3.a) Mapping pills (tanpa Replace)
        $mappingForPills = $rawMapping
            ->reject(function ($item) {
                return \Illuminate\Support\Str::contains(strtolower((string)$item->Deskription), 'replace');
            })
            ->map(function ($row) {
                $descLower = strtolower((string)$row->Deskription);
                $isExport  = \Illuminate\Support\Str::contains($descLower, 'export') && !\Illuminate\Support\Str::contains($descLower, 'local');
                $isLocal   = \Illuminate\Support\Str::contains($descLower, 'local');
                $abbr      = $row->IV_WERKS === '3000' ? 'SMG' : ($row->IV_WERKS === '2000' ? 'SBY' : $row->IV_WERKS);

                $row->pill_label = $isExport
                    ? "KMI Export {$abbr}"
                    : ($isLocal ? "KMI Local {$abbr}" : ($row->Deskription ?: $row->IV_AUART));

                return $row;
            })
            ->groupBy('IV_WERKS')
            ->map(fn($g) => $g->unique('IV_AUART')->values());

        // 4) Auto default AUART kalau cuma plant
        if ($request->filled('werks') && !$request->filled('auart') && !$request->has('q')) {
            $types = $rawMapping->where('IV_WERKS', $werks);

            $exportDefault = $types->first(function ($row) {
                $d = strtolower((string)$row->Deskription);
                return str_contains($d, 'export') && !str_contains($d, 'local') && !str_contains($d, 'replace');
            });

            $replaceDefault = $types->first(function ($row) {
                $d = strtolower((string)$row->Deskription);
                return str_contains($d, 'replace');
            });

            $default = $exportDefault
                ?? $replaceDefault
                ?? $types->first(function ($row) {
                    $d = strtolower((string)$row->Deskription);
                    return str_contains($d, 'local');
                })
                ?? $types->first();

            if ($default) {
                $payload = ['werks' => $werks, 'auart' => $default->IV_AUART, 'compact' => 1];
                return redirect()->route('po.report', ['q' => Crypt::encrypt($payload)]);
            }
        }

        // 5) LOGIKA PENGGABUNGAN Export + Replace
        $exportAuartCodes = $rawMapping
            ->filter(fn($i) => \Illuminate\Support\Str::contains(strtolower((string)$i->Deskription), 'export')
                && !\Illuminate\Support\Str::contains(strtolower((string)$i->Deskription), 'local')
                && !\Illuminate\Support\Str::contains(strtolower((string)$i->Deskription), 'replace'))
            ->pluck('IV_AUART')->unique()->toArray();

        $replaceAuartCodes = $rawMapping
            ->filter(fn($i) => \Illuminate\Support\Str::contains(strtolower((string)$i->Deskription), 'replace'))
            ->pluck('IV_AUART')->unique()->toArray();

        $auartList = [$auart];
        if (in_array($auart, $exportAuartCodes, true) && !in_array($auart, $replaceAuartCodes, true)) {
            $auartList = array_merge($exportAuartCodes, $replaceAuartCodes);
        }
        $auartList = array_values(array_unique(array_filter($auartList)));

        // 5.a) Label terpilih
        $locationAbbr = $werks === '3000' ? 'SMG' : ($werks === '2000' ? 'SBY' : $werks);
        $inExport     = in_array($auart, $exportAuartCodes, true) && !in_array($auart, $replaceAuartCodes, true);
        $descFromMap  = $rawMapping->where('IV_WERKS', $werks)->where('IV_AUART', $auart)->pluck('Deskription')->first() ?? '';

        if ($inExport) {
            $selectedDescription = "KMI Export {$locationAbbr}";
        } else {
            if (stripos((string)$descFromMap, 'local') !== false) {
                $selectedDescription = "KMI Local {$locationAbbr}";
            } else {
                $selectedDescription = $descFromMap ?: (string)$auart;
            }
        }

        // 6) Parser tanggal aman
        $safeEdatu = "COALESCE(
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
        )";

        $safeEdatuInner = "COALESCE(
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2_inner.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2_inner.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
        )";

        // 7) Overview Customer
        $rows = collect();
        if ($show) {
            // ✅ Item unik outstanding + AUART filter (IV_AUART or AUART2)
            $uniqueItemsAgg = DB::table("$t1Table as t1a")
                ->join("$t2Table as t2h", function ($j) {
                    $j->on(DB::raw('TRIM(CAST(t2h.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t1a.VBELN AS CHAR))'));
                })
                ->select(
                    't1a.VBELN',
                    't1a.POSNR',
                    't1a.MATNR',
                    't1a.WAERK',
                    DB::raw('MAX(t1a.TOTPR) AS item_total_value'),
                    DB::raw('MAX(t1a.QTY_BALANCE2) AS item_outs_qty')
                )
                ->whereRaw("CAST(t1a.QTY_BALANCE2 AS DECIMAL(18,3)) {$qtyOp} 0")
                // ✅ WERKS ikut header
                ->where('t2h.IV_WERKS_PARAM', $werks);

            $uniqueItemsAgg = $this->applyAuartT1($uniqueItemsAgg, 't1a', $auartList);

            $uniqueItemsAgg->groupBy('t1a.VBELN', 't1a.POSNR', 't1a.MATNR', 't1a.WAERK');

            // Ringkas per SO
            $soAgg = DB::table(DB::raw("({$uniqueItemsAgg->toSql()}) as t1_u"))->mergeBindings($uniqueItemsAgg)
                ->select(
                    't1_u.VBELN',
                    't1_u.WAERK',
                    DB::raw('SUM(t1_u.item_total_value) AS so_total_value'),
                    DB::raw('SUM(t1_u.item_outs_qty)   AS so_outs_qty')
                )
                ->groupBy('t1_u.VBELN', 't1_u.WAERK');

            // A) Semua outstanding per customer
            $allAggSubquery = DB::table(DB::raw("({$soAgg->toSql()}) as so_agg"))->mergeBindings($soAgg)
                ->join("$t2Table as t2a", function ($j) {
                    $j->on(DB::raw('TRIM(CAST(t2a.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(so_agg.VBELN AS CHAR))'));
                })
                ->groupBy('t2a.KUNNR')
                ->select(
                    't2a.KUNNR',
                    DB::raw("CAST(ROUND(SUM(CASE WHEN so_agg.WAERK = 'IDR' THEN so_agg.so_total_value ELSE 0 END),0) AS DECIMAL(18,0)) AS TOTAL_ALL_VALUE_IDR"),
                    DB::raw("CAST(ROUND(SUM(CASE WHEN so_agg.WAERK = 'USD' THEN so_agg.so_total_value ELSE 0 END),0) AS DECIMAL(18,0)) AS TOTAL_ALL_VALUE_USD"),
                    DB::raw("CAST(SUM(so_agg.so_outs_qty) AS DECIMAL(18,3)) AS TOTAL_OUTS_QTY")
                );

            // B) Total overdue per customer
            $overdueValueSubquery = DB::table(DB::raw("({$soAgg->toSql()}) as so_agg"))->mergeBindings($soAgg)
                ->join("$t2Table as t2_inner", function ($j) {
                    $j->on(DB::raw('TRIM(CAST(t2_inner.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(so_agg.VBELN AS CHAR))'));
                })
                ->whereRaw("{$safeEdatuInner} < CURDATE()")
                ->groupBy('t2_inner.KUNNR')
                ->select(
                    't2_inner.KUNNR',
                    DB::raw("CAST(ROUND(SUM(CASE WHEN so_agg.WAERK = 'IDR' THEN so_agg.so_total_value ELSE 0 END),0) AS DECIMAL(18,0)) AS TOTAL_OVERDUE_VALUE_IDR"),
                    DB::raw("CAST(ROUND(SUM(CASE WHEN so_agg.WAERK = 'USD' THEN so_agg.so_total_value ELSE 0 END),0) AS DECIMAL(18,0)) AS TOTAL_OVERDUE_VALUE_USD")
                );

            // Query utama customer
            $rowsQuery = DB::table("$t2Table as t2")
                ->leftJoinSub($allAggSubquery, 'agg_all', fn($j) => $j->on('t2.KUNNR', '=', 'agg_all.KUNNR'))
                ->leftJoinSub($overdueValueSubquery, 'agg_overdue', fn($j) => $j->on('t2.KUNNR', '=', 'agg_overdue.KUNNR'))
                ->select(
                    't2.KUNNR',
                    DB::raw('MAX(t2.NAME1) AS NAME1'),
                    DB::raw('COALESCE(MAX(agg_all.TOTAL_ALL_VALUE_IDR),0)  AS TOTAL_ALL_VALUE_IDR'),
                    DB::raw('COALESCE(MAX(agg_all.TOTAL_ALL_VALUE_USD),0)  AS TOTAL_ALL_VALUE_USD'),
                    DB::raw('COALESCE(MAX(agg_overdue.TOTAL_OVERDUE_VALUE_IDR),0) AS TOTAL_OVERDUE_VALUE_IDR'),
                    DB::raw('COALESCE(MAX(agg_overdue.TOTAL_OVERDUE_VALUE_USD),0) AS TOTAL_OVERDUE_VALUE_USD'),
                    DB::raw("COUNT(DISTINCT t2.VBELN) AS SO_TOTAL_COUNT"),
                    DB::raw("COUNT(DISTINCT CASE WHEN {$safeEdatu} < CURDATE() THEN t2.VBELN ELSE NULL END) AS SO_LATE_COUNT")
                )
                ->where('t2.IV_WERKS_PARAM', $werks);

            // ✅ filter AUART header yang benar (include AUART2)
            $rowsQuery = $this->applyAuartT2($rowsQuery, $auartList, $t1Table);

            // ✅ pastikan ada item outstanding dalam konteks AUART
            $rowsQuery->whereExists(function ($q) use ($auartList, $t1Table, $qtyOp) {
                $q->select(DB::raw(1))
                ->from("$t1Table as t1_check")
                ->whereColumn('t1_check.VBELN', 't2.VBELN')
                ->where(function ($w) use ($auartList) {
                    $w->whereIn('t1_check.IV_AUART_PARAM', $auartList)
                        ->orWhereIn('t1_check.AUART2', $auartList);
                })
                ->whereRaw("CAST(t1_check.QTY_BALANCE2 AS DECIMAL(18,3)) {$qtyOp} 0");
            });
            $rows = $rowsQuery
                ->whereNotNull('t2.NAME1')->where('t2.NAME1', '!=', '')
                ->groupBy('t2.KUNNR')
                ->orderBy('NAME1', 'asc')
                ->paginate(25)->withQueryString();
        }

        // 8) Performance details (✅ OR AUART2)
        $safeEdatuPerf = "COALESCE(
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
        )";

        $inExportPerf = in_array($auart, $exportAuartCodes, true) && !in_array($auart, $replaceAuartCodes, true);
        $targetAuarts = $inExportPerf ? array_values(array_unique(array_merge($exportAuartCodes, $replaceAuartCodes))) : [$auart];

        $performanceData = collect();

        if ($showCharts && $show) {
            $performanceQueryBase = DB::table("$t2Table as t2")
                ->join(
                    "$t1Table as t1",
                    DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                    '=',
                    DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
                )
                ->where('t2.IV_WERKS_PARAM', $werks)
                ->whereRaw("CAST(t1.QTY_BALANCE2 AS DECIMAL(18,3)) {$qtyOp} 0")
                ->where(function ($w) use ($targetAuarts) {
                    $w->whereIn('t2.IV_AUART_PARAM', $targetAuarts)
                    ->orWhereIn('t1.AUART2', $targetAuarts);
                });

            $perf = (clone $performanceQueryBase)
                ->select(
                    DB::raw('COUNT(DISTINCT t2.VBELN) as total_so'),
                    DB::raw("CAST(ROUND(SUM(CASE WHEN t2.WAERK = 'IDR' AND {$safeEdatuPerf} < CURDATE() THEN CAST(t1.TOTPR AS DECIMAL(18,2)) ELSE 0 END), 0) AS DECIMAL(18,0)) as total_value_idr"),
                    DB::raw("CAST(ROUND(SUM(CASE WHEN t2.WAERK = 'USD' AND {$safeEdatuPerf} < CURDATE() THEN CAST(t1.TOTPR AS DECIMAL(18,2)) ELSE 0 END), 0) AS DECIMAL(18,0)) as total_value_usd"),
                    DB::raw("COUNT(DISTINCT CASE WHEN {$safeEdatuPerf} < CURDATE() THEN t2.VBELN ELSE NULL END) as overdue_so_count"),
                    DB::raw("COUNT(DISTINCT CASE WHEN DATEDIFF(CURDATE(), {$safeEdatuPerf}) BETWEEN 1 AND 30 THEN t2.VBELN ELSE NULL END) as overdue_1_30"),
                    DB::raw("COUNT(DISTINCT CASE WHEN DATEDIFF(CURDATE(), {$safeEdatuPerf}) BETWEEN 31 AND 60 THEN t2.VBELN ELSE NULL END) as overdue_31_60"),
                    DB::raw("COUNT(DISTINCT CASE WHEN DATEDIFF(CURDATE(), {$safeEdatuPerf}) BETWEEN 61 AND 90 THEN t2.VBELN ELSE NULL END) as overdue_61_90"),
                    DB::raw("COUNT(DISTINCT CASE WHEN DATEDIFF(CURDATE(), {$safeEdatuPerf}) > 90 THEN t2.VBELN ELSE NULL END) as overdue_over_90")
                )
                ->first();

            if ($perf && (int)($perf->total_so ?? 0) > 0) {
                $performanceData->push((object)[
                    'Deskription'      => $inExportPerf ? "KMI Export {$locationAbbr}" : ($descFromMap ?: $auart),
                    'IV_WERKS'         => $werks,
                    'IV_AUART'         => $auart,
                    'total_so'         => (int)$perf->total_so,
                    'total_value_idr'  => (float)$perf->total_value_idr,
                    'total_value_usd'  => (float)$perf->total_value_usd,
                    'overdue_so_count' => (int)$perf->overdue_so_count,
                    'overdue_1_30'     => (int)$perf->overdue_1_30,
                    'overdue_31_60'    => (int)$perf->overdue_31_60,
                    'overdue_61_90'    => (int)$perf->overdue_61_90,
                    'overdue_over_90'  => (int)$perf->overdue_over_90,
                ]);
            }
        }

        // 9) Small Qty (≤5) by Customer (✅ OR AUART2)
        $smallQtyByCustomer = collect();
        $totalSmallQtyOutstanding = 0;

        if ($showCharts && $show) {
            $smallQtyBase = DB::table("$t2Table as t2")
                ->join(
                    "$t1Table as t1",
                    DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                    '=',
                    DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
                )
                ->where('t2.IV_WERKS_PARAM', $werks)
                ->where(function ($w) use ($auartList) {
                    $w->whereIn('t2.IV_AUART_PARAM', $auartList)
                    ->orWhereIn('t1.AUART2', $auartList);
                })
                // small qty ini hanya relevan outstanding, jadi tetap >0 dan <=5
                ->whereRaw('CAST(t1.QTY_BALANCE2 AS DECIMAL(18,3)) > 0')
                ->whereRaw('CAST(t1.QTY_BALANCE2 AS DECIMAL(18,3)) <= 5')
                ->where('t1.QTY_GI', '>', 0);

            $smallQtyByCustomer = (clone $smallQtyBase)
                ->select('t2.NAME1', 't2.IV_WERKS_PARAM', DB::raw('COUNT(DISTINCT t2.VBELN) AS so_count'))
                ->groupBy('t2.NAME1', 't2.IV_WERKS_PARAM')
                ->orderBy('t2.NAME1')
                ->get();

            $totalSmallQtyOutstanding = (clone $smallQtyBase)->count('t1.POSNR');
        }

        // 10) Kirim ke view
        return view('po_report.po_report', [
            'mapping'  => $mappingForPills,
            'selected' => ['werks' => $werks, 'auart' => $auart],
            'selectedDescription' => $selectedDescription,
            'rows'  => $rows,
            'compact' => $compact,
            'show'  => $show,
            'performanceData' => $performanceData,
            'smallQtyByCustomer' => $smallQtyByCustomer,
            'totalSmallQtyOutstanding' => $totalSmallQtyOutstanding,

            'search'         => $search,
            'needAutoExpand' => $needAutoExpand,
            'highlightKunnr' => $highlightKunnr,
            'highlightVbeln' => $highlightVbeln,
            'highlightBstnk' => $highlightBstnk,
            'highlightPosnr' => $highlightPosnr,
            'mode' => $mode,
            'showCharts' => $showCharts,
        ]);
    }

    /**
     * START export (POST) -> validasi + buat token q lalu redirect ke GET streamer.
     */
    public function exportDataStart(Request $request)
    {
        // Validasi dasar
        $validated = $request->validate([
            'item_ids'    => 'required|array|min:1',
            'export_type' => 'required|string|in:pdf,excel',
            'werks'       => 'required|string',
            'auart'       => 'required|string',
            'mode' => 'nullable|in:outstanding,complete',
        ]);

        // Sanitasi ID → hanya angka
        $ids = collect($validated['item_ids'])
            ->map(fn($v) => (int)preg_replace('/\D+/', '', (string)$v))
            ->filter(fn($v) => $v > 0)
            ->unique()
            ->values()
            ->all();

        if (empty($ids)) {
            return back()->withErrors('Tidak ada item yang valid untuk diekspor.');
        }

        // Packing ke token "t" (cache)
        $t = $this->packToToken([
            'item_ids'    => $ids,
            'export_type' => $validated['export_type'],
            'werks'       => $validated['werks'],
            'auart'       => $validated['auart'],
            'mode' => $request->input('mode', 'outstanding'),
        ]);

        // Redirect 303 ke GET streamer
        return redirect()->route('po.export.show', ['t' => $t], 303);
    }

    /**
     * GET streamer: bangun file & kirim response (Excel/PDF) dari payload terenkripsi.
     */
    public function exportDataShow(Request $request)
    {
        // ============================================================
        // 1) Ambil payload dari token "t" (cache) ATAU fallback "q"
        //    Sekaligus tentukan MODE dan tabel yang dipakai (t1/t2/t3)
        // ============================================================

        $data = [];

        if ($request->filled('t')) {
            // Dari token cache
            $data = $this->unpackFromToken($request->query('t'));

            $mode = strtolower((string)($data['mode'] ?? 'outstanding'));
            if (!in_array($mode, ['outstanding', 'complete'], true)) $mode = 'outstanding';

            $t1Table = $mode === 'complete' ? 'so_yppr079_t1_comp' : 'so_yppr079_t1';
            $t2Table = $mode === 'complete' ? 'so_yppr079_t2_comp' : 'so_yppr079_t2';
            $t3Table = $mode === 'complete' ? 'so_yppr079_t3_comp' : 'so_yppr079_t3';
        } else {
            // Fallback legacy "q" terenkripsi
            if (!$request->filled('q')) {
                return back()->withErrors('Payload export tidak ditemukan.');
            }

            try {
                $data = \Illuminate\Support\Facades\Crypt::decrypt($request->query('q'));
                if (!is_array($data)) $data = [];
            } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                return back()->withErrors('Token export tidak valid.');
            }

            // ✅ setelah decrypt q, baru set mode & table
            $mode = strtolower((string)($data['mode'] ?? 'outstanding'));
            if (!in_array($mode, ['outstanding', 'complete'], true)) $mode = 'outstanding';

            $t1Table = $mode === 'complete' ? 'so_yppr079_t1_comp' : 'so_yppr079_t1';
            $t2Table = $mode === 'complete' ? 'so_yppr079_t2_comp' : 'so_yppr079_t2';
            $t3Table = $mode === 'complete' ? 'so_yppr079_t3_comp' : 'so_yppr079_t3';
        }

        // ============================================================
        // 2) Ambil & sanitasi ulang parameter
        // ============================================================

        $werks      = (string)($data['werks'] ?? '');
        $auart      = (string)($data['auart'] ?? '');
        $exportType = (string)($data['export_type'] ?? 'pdf');

        $ids = collect($data['item_ids'] ?? [])
            ->map(fn($v) => (int)preg_replace('/\D+/', '', (string)$v))
            ->filter(fn($v) => $v > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty() || $werks === '' || $auart === '' || !in_array($exportType, ['pdf', 'excel'], true)) {
            return back()->withErrors('Parameter export tidak lengkap/valid.');
        }

        // ============================================================
        // 3) Konteks AUART: gabungkan Export + Replace bila konteks Export aktif
        // ============================================================

        $rawMapping = DB::table('maping')->select('IV_AUART', 'Deskription')->get();

        $exportAuartCodes = $rawMapping
            ->filter(fn($i) => Str::contains(strtolower((string)$i->Deskription), 'export')
                && !Str::contains(strtolower((string)$i->Deskription), 'local')
                && !Str::contains(strtolower((string)$i->Deskription), 'replace'))
            ->pluck('IV_AUART')->unique()->toArray();

        $replaceAuartCodes = $rawMapping
            ->filter(fn($i) => Str::contains(strtolower((string)$i->Deskription), 'replace'))
            ->pluck('IV_AUART')->unique()->toArray();

        $auartList = in_array($auart, $exportAuartCodes, true)
            ? array_values(array_unique(array_merge($exportAuartCodes, $replaceAuartCodes)))
            : [$auart];

        // ============================================================
        // 4) Ambil triplet (VBELN, POSNR, MATNR) dari ID pilihan
        //    ✅ Harus pakai $t1Table (outstanding/complete)
        // ============================================================

        $itemKeys = DB::table($t1Table)
            ->whereIn('id', $ids->all())
            ->select('VBELN', 'POSNR', 'MATNR')
            ->get();

        $triples = $itemKeys->map(fn($r) => [
            'VBELN' => $r->VBELN,
            'POSNR' => $r->POSNR,
            'MATNR' => $r->MATNR,
        ])->unique();

        if ($triples->isEmpty()) {
            return response()->json(['error' => 'No unique items found for export.'], 400);
        }

        // ============================================================
        // 5) Parser tanggal aman (EDATU)
        // ============================================================

        $safeEdatu = "COALESCE(
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
        )";

        // ============================================================
        // 6) Subquery remark gabungan (dibatasi item terpilih)
        //    - selectedPairs pakai $t1Table
        //    - auartKeys pakai $t1Table (IV_AUART_PARAM + AUART2)
        // ============================================================

        $selectedPairs = DB::table("$t1Table as tsel")
            ->whereIn('tsel.id', $ids->all())
            ->select(
                DB::raw('TRIM(CAST(tsel.VBELN AS CHAR)) as VBELN'),
                DB::raw("LPAD(TRIM(CAST(tsel.POSNR AS CHAR)), 6, '0') as POSNR_DB")
            )
            ->groupBy(
                DB::raw('TRIM(CAST(tsel.VBELN AS CHAR))'),
                DB::raw("LPAD(TRIM(CAST(tsel.POSNR AS CHAR)), 6, '0')")
            );

        $auartKeys = DB::query()
            ->fromSub(function ($u) use ($ids, $t1Table) {
                $u->from("$t1Table as a")
                    ->select(DB::raw('TRIM(a.IV_AUART_PARAM) as AUART'))
                    ->whereIn('a.id', $ids->all())
                    ->whereNotNull('a.IV_AUART_PARAM')
                    ->whereRaw("TRIM(a.IV_AUART_PARAM) <> ''")
                    ->unionAll(
                        DB::table("$t1Table as b")
                            ->select(DB::raw('TRIM(b.AUART2) as AUART'))
                            ->whereIn('b.id', $ids->all())
                            ->whereNotNull('b.AUART2')
                            ->whereRaw("TRIM(b.AUART2) <> ''")
                    );
            }, 'uu')
            ->select('uu.AUART');

        $remarksConcat = DB::table('item_remarks as ir')
            ->joinSub($selectedPairs, 'sel', function ($j) {
                $j->on(DB::raw('TRIM(CAST(ir.VBELN AS CHAR))'), '=', 'sel.VBELN')
                ->on(DB::raw("LPAD(TRIM(CAST(ir.POSNR AS CHAR)), 6, '0')"), '=', 'sel.POSNR_DB');
            })
            ->leftJoin('users as u', 'u.id', '=', 'ir.user_id')
            ->whereIn(DB::raw('TRIM(ir.IV_AUART_PARAM)'), $auartKeys)
            ->whereRaw("TRIM(COALESCE(ir.remark,'')) <> ''")
            ->select(
                'ir.VBELN',
                'ir.POSNR',
                DB::raw("
                    GROUP_CONCAT(
                        DISTINCT CONCAT(COALESCE(u.name,'Guest'), ': ', TRIM(ir.remark))
                        ORDER BY ir.created_at
                        SEPARATOR '\n'
                    ) AS REMARKS
                ")
            )
            ->groupBy('ir.VBELN', 'ir.POSNR');

        // ============================================================
        // 6.1) ✅ Container number (NAME4) khusus MODE COMPLETE (t3_comp)
        //      Kita buat 1 baris per VBELN+POSNR, hasilnya 1 kolom saja.
        // ============================================================

        $containerConcat = null;
        if ($mode === 'complete') {
            $containerConcat = DB::table("$t3Table as t3")
            ->selectRaw("
                TRIM(CAST(t3.VBELN AS CHAR)) as VBELN,
                LPAD(CAST(TRIM(t3.POSNR) AS UNSIGNED), 6, '0') as POSNR_DB,
                GROUP_CONCAT(
                    DISTINCT TRIM(COALESCE(t3.NAME4,''))
                    ORDER BY TRIM(COALESCE(t3.NAME4,''))
                    SEPARATOR '\n'
                ) AS CONTAINER_NUMBER
            ")
            ->whereRaw("TRIM(COALESCE(t3.NAME4,'')) <> ''")
            ->groupBy(DB::raw("TRIM(CAST(t3.VBELN AS CHAR))"), DB::raw("LPAD(CAST(TRIM(t3.POSNR) AS UNSIGNED), 6, '0')"));
        }

        // ============================================================
        // 7) Query utama items
        //    ✅ WAJIB pakai $t1Table & $t2Table
        // ============================================================

        $itemsQuery = DB::table("$t1Table as t1")
            ->leftJoin(
                "$t2Table as t2",
                DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                '=',
                DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
            )
            ->leftJoinSub($remarksConcat, 'rc', function ($j) {
                $j->on('rc.VBELN', '=', 't1.VBELN')
                ->on('rc.POSNR', '=', DB::raw("LPAD(TRIM(CAST(t1.POSNR AS CHAR)), 6, '0')"));
            });

        // ✅ join container hanya untuk COMPLETE
        if ($mode === 'complete' && $containerConcat) {
            $itemsQuery->leftJoinSub($containerConcat, 'cc', function ($j) {
                $j->on('cc.VBELN', '=', DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'))
                ->on('cc.POSNR_DB', '=', DB::raw("LPAD(CAST(TRIM(t1.POSNR) AS UNSIGNED), 6, '0')"));
            });
        }

        // select fields
        $selects = [
            DB::raw('t1.VBELN as SO'),
            DB::raw("TRIM(LEADING '0' FROM t1.POSNR) AS POSNR"),
            DB::raw("CASE WHEN t1.MATNR REGEXP '^[0-9]+$' THEN TRIM(LEADING '0' FROM t1.MATNR) ELSE t1.MATNR END AS MATNR"),
            DB::raw('MAX(t2.BSTNK)  as PO'),
            DB::raw('MAX(t2.NAME1)  as CUSTOMER'),
            DB::raw('MAX(t1.MAKTX)  as MAKTX'),
            DB::raw('MAX(t1.KWMENG) as QTY_PO'),
            DB::raw('MAX(t1.QTY_GI) as QTY_GI'),
            DB::raw('MAX(t1.QTY_BALANCE2) as QTY_BALANCE2'),
            DB::raw('MAX(t1.KALAB)  as KALAB'),
            DB::raw('MAX(t1.KALAB2) as KALAB2'),
            DB::raw("MAX(DATE_FORMAT({$safeEdatu}, '%d-%m-%Y')) AS EDATU_FORMATTED"),
            DB::raw('MAX(t1.WAERK)  as WAERK'),
            DB::raw("COALESCE(MAX(rc.REMARKS), '') AS REMARK"),
        ];

        if ($mode === 'complete') {

            $selects[] = DB::raw("
                COALESCE(
                    NULLIF(MAX(cc.CONTAINER_NUMBER), ''),
                    MAX(NULLIF(TRIM(t1.NAME4), '')),
                    ''
                ) AS CONTAINER_NUMBER
            ");

        } else {

            // outstanding: ambil dari ITEM (t1.NAME4), jangan kosong
            $selects[] = DB::raw("
                COALESCE(
                    MAX(NULLIF(TRIM(t1.NAME4), '')),
                    ''
                ) AS CONTAINER_NUMBER
            ");
        }


        $items = $itemsQuery
            ->where('t2.IV_WERKS_PARAM', $werks)
            ->where(function ($w) use ($auartList) {
                $w->whereIn('t1.IV_AUART_PARAM', $auartList)
                ->orWhereIn('t1.AUART2', $auartList);
            })
            ->where(function ($query) use ($triples) {
                foreach ($triples as $p) {
                    $query->orWhere(function ($q) use ($p) {
                        $q->where('t1.VBELN', $p['VBELN'])
                        ->where('t1.POSNR', $p['POSNR'])
                        ->where('t1.MATNR', $p['MATNR']);
                    });
                }
            })
            ->select($selects)
            ->groupBy('t1.VBELN', 't1.POSNR', 't1.MATNR')
            ->orderBy('t1.VBELN')
            ->orderByRaw('CAST(t1.POSNR AS UNSIGNED)')
            ->get();

        if ($items->isEmpty()) {
            return back()->withErrors('Tidak ada item yang valid untuk diekspor.');
        }

        // ============================================================
        // 8) Nama file & render (Excel / PDF)
        // ============================================================

        $locationName = $this->resolveLocationName($werks);
        $auartDesc = DB::table('maping')->where('IV_WERKS', $werks)->where('IV_AUART', $auart)->value('Deskription');

        if ($exportType === 'excel') {
            $fileName = $this->buildFileName("PO_Items_{$locationName}_{$auart}", 'xlsx');

            // ✅ Kirim $mode agar PoItemsExport bisa bedakan kolom complete/outstanding
            return Excel::download(new PoItemsExport($items, $mode), $fileName);
        }

        $pdfBinary = Pdf::loadView('po_report.po_pdf_template', [
            'items'            => $items,
            'locationName'     => $locationName,
            'auartDescription' => $auartDesc,
            'werks'            => $werks,
            'auart'            => $auart,
            'today'            => now(),
            'mode'             => $mode,
        ])->setPaper('a4', 'landscape')->output();

        $fileName = $this->buildFileName("PO_Items_{$locationName}_{$auart}", 'pdf');
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
    /**
     * Mendapatkan data performance (KPI) berdasarkan KUNNR (untuk klik di tabel).
     */
    public function apiPerformanceByCustomer(Request $request)
    {
        $request->validate([
            'werks' => 'required|string',
            'auart' => 'required|string',
            'kunnr' => 'required|string',
        ]);

        $werks = $request->query('werks');
        $auart = $request->query('auart');
        $kunnr = $request->query('kunnr');

        $rawMapping = DB::table('maping')->get();
        $exportAuartCodes = $rawMapping
            ->filter(fn($i) => Str::contains(strtolower($i->Deskription), 'export')
                && !Str::contains(strtolower($i->Deskription), 'local')
                && !Str::contains(strtolower($i->Deskription), 'replace'))
            ->pluck('IV_AUART')->unique()->toArray();

        $replaceAuartCodes = $rawMapping
            ->filter(fn($i) => Str::contains(strtolower($i->Deskription), 'replace'))
            ->pluck('IV_AUART')->unique()->toArray();

        $inExport = in_array($auart, $exportAuartCodes) && !in_array($auart, $replaceAuartCodes);
        $targetAuarts = $inExport ? array_unique(array_merge($exportAuartCodes, $replaceAuartCodes)) : [$auart];
        if (empty($targetAuarts) || !in_array($auart, $targetAuarts)) {
            $targetAuarts = [$auart];
        }

        $safeEdatuPerf = "
        COALESCE(
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
        )";

        $descForRow = $rawMapping
            ->where('IV_WERKS', $werks)
            ->where('IV_AUART', $auart)
            ->pluck('Deskription')
            ->first();

        $perfQuery = DB::table('so_yppr079_t2 as t2')
            ->join(
                'so_yppr079_t1 as t1',
                DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                '=',
                DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
            )
            ->where('t2.IV_WERKS_PARAM', $werks)
            ->where(function ($w) use ($targetAuarts) {
                $w->whereIn('t2.IV_AUART_PARAM', $targetAuarts)
                ->orWhereIn('t1.AUART2', $targetAuarts);
            })
            ->whereRaw('CAST(t1.QTY_BALANCE2 AS DECIMAL(18,3)) > 0')
            ->where('t2.KUNNR', $kunnr)
            ->select(
                DB::raw('COUNT(DISTINCT t2.VBELN) as total_so'),
                DB::raw("CAST(ROUND(SUM(CASE WHEN t2.WAERK = 'IDR' AND {$safeEdatuPerf} < CURDATE() THEN CAST(t1.TOTPR AS DECIMAL(18,2)) ELSE 0 END), 0) AS DECIMAL(18,0)) as total_value_idr"),
                DB::raw("CAST(ROUND(SUM(CASE WHEN t2.WAERK = 'USD' AND {$safeEdatuPerf} < CURDATE() THEN CAST(t1.TOTPR AS DECIMAL(18,2)) ELSE 0 END), 0) AS DECIMAL(18,0)) as total_value_usd"),
                DB::raw("COUNT(DISTINCT CASE WHEN {$safeEdatuPerf} < CURDATE() THEN t2.VBELN ELSE NULL END) as overdue_so_count"),
                DB::raw("COUNT(DISTINCT CASE WHEN DATEDIFF(CURDATE(), {$safeEdatuPerf}) BETWEEN 1 AND 30 THEN t2.VBELN ELSE NULL END) as overdue_1_30"),
                DB::raw("COUNT(DISTINCT CASE WHEN DATEDIFF(CURDATE(), {$safeEdatuPerf}) BETWEEN 31 AND 60 THEN t2.VBELN ELSE NULL END) as overdue_31_60"),
                DB::raw("COUNT(DISTINCT CASE WHEN DATEDIFF(CURDATE(), {$safeEdatuPerf}) BETWEEN 61 AND 90 THEN t2.VBELN ELSE NULL END) as overdue_61_90"),
                DB::raw("COUNT(DISTINCT CASE WHEN DATEDIFF(CURDATE(), {$safeEdatuPerf}) > 90 THEN t2.VBELN ELSE NULL END) as overdue_over_90")
            )
            ->first();

        $performanceData = collect();
        if ($perfQuery && (int) ($perfQuery->total_so ?? 0) > 0) {
            $performanceData->push((object) [
                'Deskription'      => $inExport ? 'KMI Export' : ($descForRow ?: $auart),
                'IV_WERKS'         => $werks,
                'IV_AUART'         => $auart,
                'total_so'         => (int) $perfQuery->total_so,
                'total_value_idr'  => (float) $perfQuery->total_value_idr,
                'total_value_usd'  => (float) $perfQuery->total_value_usd,
                'overdue_so_count' => (int) $perfQuery->overdue_so_count,
                'overdue_1_30'     => (int) $perfQuery->overdue_1_30,
                'overdue_31_60'    => (int) $perfQuery->overdue_31_60,
                'overdue_61_90'    => (int) $perfQuery->overdue_61_90,
                'overdue_over_90'  => (int) $perfQuery->overdue_over_90,
            ]);
        }

        $customerName = DB::table('so_yppr079_t2')
            ->where('KUNNR', $kunnr)->where('IV_WERKS_PARAM', $werks)
            ->value('NAME1') ?? $kunnr;

        return response()->json([
            'ok' => true,
            'data' => $performanceData,
            'customer_name' => $customerName,
            'is_export_context' => $inExport,
        ]);
    }

    /*** ====== Legacy/baru: endpoint remarks (tanpa perubahan) ====== ***/
    public function apiSavePoRemark(Request $request)
    {
        $validated = $request->validate([
            'werks'  => 'required|string',
            'auart'  => 'required|string',
            'vbeln'  => 'required|string',
            'posnr'  => 'required|string',
            'remark' => 'nullable|string|max:100',
        ]);

        $posnrDb    = str_pad(preg_replace('/\D/', '', (string)$validated['posnr']), 6, '0', STR_PAD_LEFT);
        $remarkText = trim($validated['remark'] ?? '');
        $userId     = Auth::id();

        if (!$userId) {
            return response()->json(['ok' => false, 'message' => 'Silakan login untuk menambahkan catatan.'], 401);
        }

        $keys = [
            'IV_WERKS_PARAM' => $validated['werks'],
            'IV_AUART_PARAM' => $validated['auart'],
            'VBELN'          => $validated['vbeln'],
            'POSNR'          => $posnrDb,
        ];

        try {
            if ($remarkText === '') {
                return response()->json([
                    'ok' => false,
                    'message' => 'Untuk menghapus catatan, gunakan endpoint delete khusus.',
                ], 400);
            }

            $id = DB::table('item_remarks')->insertGetId($keys + [
                'remark'     => $remarkText,
                'user_id'    => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'ok' => true,
                'message' => 'Catatan PO berhasil ditambahkan.',
                'id' => $id,
            ]);
        } catch (\Exception $e) {
            return response()->json(['ok' => false, 'message' => 'Gagal menambahkan catatan PO.'], 500);
        }
    }

    public function apiListPoRemarks(Request $request)
    {
        $request->validate([
            'werks' => 'required|string',
            'auart' => 'required|string',
            'vbeln' => 'required|string',
            'posnr' => 'required|string',
        ]);

        $werks = trim((string) $request->werks);
        $auart = trim((string) $request->auart);
        $vbeln = trim((string) $request->vbeln);

        // POSNR DB selalu 6 digit
        $posnrDb = str_pad(preg_replace('/\D/', '', (string) $request->posnr), 6, '0', STR_PAD_LEFT);

        // ✅ konteks AUART (export+replace)
        $ctxAuarts = $this->resolveAuartListForContext($auart, $werks);
        if (empty($ctxAuarts) && $auart !== '') $ctxAuarts = [$auart];

        $rows = DB::table('item_remarks as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->whereRaw('TRIM(CAST(r.VBELN AS CHAR)) = TRIM(?)', [$vbeln])
            ->whereRaw("LPAD(TRIM(CAST(r.POSNR AS CHAR)), 6, '0') = ?", [$posnrDb])
            ->where('r.IV_WERKS_PARAM', $werks) // ✅ jangan lintas plant
            ->whereIn(DB::raw('TRIM(r.IV_AUART_PARAM)'), $ctxAuarts)
            ->whereRaw("TRIM(COALESCE(r.remark,'')) <> ''")
            ->orderByDesc(DB::raw('COALESCE(r.updated_at, r.created_at)'))
            ->select(
                'r.id',
                'r.remark',
                'r.user_id',
                'r.IV_WERKS_PARAM',
                'r.IV_AUART_PARAM',
                DB::raw("DATE_FORMAT(COALESCE(r.updated_at, r.created_at),'%Y-%m-%d %H:%i:%s') as created_at"),
                DB::raw("COALESCE(u.name, CONCAT('User#', r.user_id)) as user_name")
            )
            ->get();

        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function apiCreatePoRemark(Request $request)
    {
        $request->validate([
            'werks'  => 'required|string',
            'auart'  => 'required|string',
            'vbeln'  => 'required|string',
            'posnr'  => 'required|string',
            'remark' => 'required|string|max:100',
        ]);

        $userId = Auth::id();
        if (!$userId) return response()->json(['ok' => false, 'message' => 'Silakan login.'], 401);

        $posnrDb = str_pad(preg_replace('/\D/', '', $request->posnr), 6, '0', STR_PAD_LEFT);

        $id = DB::table('item_remarks')->insertGetId([
            'IV_WERKS_PARAM' => $request->werks,
            'IV_AUART_PARAM' => $request->auart,
            'VBELN'          => $request->vbeln,
            'POSNR'          => $posnrDb,
            'remark'         => trim($request->remark),
            'user_id'        => $userId,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return response()->json(['ok' => true, 'id' => $id, 'message' => 'Catatan ditambahkan.']);
    }

    public function apiUpdatePoRemark(Request $request, $id)
    {
        $request->validate(['remark' => 'required|string|max:100']);
        $userId = Auth::id();
        if (!$userId) return response()->json(['ok' => false, 'message' => 'Silakan login.'], 401);

        $row = DB::table('item_remarks')->where('id', $id)->first();
        if (!$row) return response()->json(['ok' => false, 'message' => 'Data tidak ditemukan.'], 404);
        if ((int)$row->user_id !== (int)$userId) {
            return response()->json(['ok' => false, 'message' => 'Anda hanya dapat mengubah catatan milik Anda.'], 403);
        }

        DB::table('item_remarks')->where('id', $id)->update([
            'remark' => trim($request->remark),
            'updated_at' => now()
        ]);

        return response()->json(['ok' => true, 'message' => 'Catatan diperbarui.']);
    }

    public function apiDeletePoRemark(Request $request, $id)
    {
        $userId = Auth::id();
        if (!$userId) return response()->json(['ok' => false, 'message' => 'Silakan login.'], 401);

        $row = DB::table('item_remarks')->where('id', $id)->first();
        if (!$row) return response()->json(['ok' => false, 'message' => 'Data tidak ditemukan.'], 404);
        if ((int)$row->user_id !== (int)$userId) {
            return response()->json(['ok' => false, 'message' => 'Anda hanya dapat menghapus catatan milik Anda.'], 403);
        }

        DB::table('item_remarks')->where('id', $id)->delete();
        return response()->json(['ok' => true, 'message' => 'Catatan dihapus.']);
    }
}
