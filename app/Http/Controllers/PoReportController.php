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

class PoReportController extends Controller
{
    /** Halaman report (tabel) */
    // FILE: app/Http/Controllers/PoReportController.php

    public function index(Request $request)
    {
        // 1) Terima & merge parameter terenkripsi (q) bila ada
        if ($request->has('q')) {
            try {
                $data = \Illuminate\Support\Facades\Crypt::decrypt($request->query('q'));
                if (is_array($data)) {
                    $request->merge($data);
                }
            } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                return redirect()->route('dashboard')->withErrors('Link Report tidak valid.');
            }
        }

        // 2) Ambil filter utama
        $werks   = $request->query('werks');                 // '2000' | '3000'
        $auart   = $request->query('auart');                 // kode AUART
        $compact = $request->boolean('compact', true);
        $show    = filled($werks) && filled($auart);

        // 3) Mapping AUART mentah (tanpa mem-filter 'Replace' dulu)
        $rawMapping = DB::table('maping')
            ->select('IV_WERKS', 'IV_AUART', 'Deskription')
            ->orderBy('IV_WERKS')->orderBy('IV_AUART')
            ->get();

        // 3.a) Siapkan mapping untuk NAV PILLS (tanpa Replace) + label paksa "KMI Export/Local SMG|SBY"
        $mappingForPills = $rawMapping
            ->reject(function ($item) {
                return \Illuminate\Support\Str::contains(strtolower((string)$item->Deskription), 'replace');
            })
            ->map(function ($row) {
                $descLower = strtolower((string)$row->Deskription);
                $isExport  = \Illuminate\Support\Str::contains($descLower, 'export') && !\Illuminate\Support\Str::contains($descLower, 'local');
                $isLocal   = \Illuminate\Support\Str::contains($descLower, 'local');
                $abbr      = $row->IV_WERKS === '3000' ? 'SMG' : ($row->IV_WERKS === '2000' ? 'SBY' : $row->IV_WERKS);

                // Tambahkan properti label untuk pills
                $row->pill_label = $isExport
                    ? "KMI Export {$abbr}"
                    : ($isLocal ? "KMI Local {$abbr}" : ($row->Deskription ?: $row->IV_AUART));

                return $row;
            })
            ->groupBy('IV_WERKS')
            ->map(fn($g) => $g->unique('IV_AUART')->values());

        // 4) Auto-pilih default AUART jika hanya plant yang dikirim (tanpa q)
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
                return redirect()->route('po.report', ['q' => \Illuminate\Support\Facades\Crypt::encrypt($payload)]);
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
        if (in_array($auart, $exportAuartCodes) && !in_array($auart, $replaceAuartCodes)) {
            $auartList = array_merge($exportAuartCodes, $replaceAuartCodes);
        }
        $auartList = array_unique(array_filter($auartList));

        // 5.a) Tentukan label terpilih (untuk judul/header) TANPA mengandalkan Deskription untuk Export
        $locationAbbr = $werks === '3000' ? 'SMG' : ($werks === '2000' ? 'SBY' : $werks);
        $inExport     = in_array($auart, $exportAuartCodes) && !in_array($auart, $replaceAuartCodes);
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

        // 7) Overview Customer (tabel utama)
        $rows = collect();
        if ($show) {
            // A) Agregat semua outstanding per customer (pisah USD & IDR)
            $allAggSubquery = DB::table('so_yppr079_t1 as t1a')
                ->join('so_yppr079_t2 as t2a', 't2a.VBELN', '=', 't1a.VBELN')
                ->select(
                    't2a.KUNNR',
                    DB::raw('CAST(SUM(CAST(t1a.QTY_BALANCE2 AS DECIMAL(18,3))) AS DECIMAL(18,3)) AS TOTAL_OUTS_QTY'),
                    DB::raw("CAST(ROUND(SUM(CASE WHEN t1a.WAERK = 'IDR' THEN CAST(t1a.TOTPR AS DECIMAL(18,2)) ELSE 0 END), 0) AS DECIMAL(18,0)) AS TOTAL_ALL_VALUE_IDR"),
                    DB::raw("CAST(ROUND(SUM(CASE WHEN t1a.WAERK = 'USD' THEN CAST(t1a.TOTPR AS DECIMAL(18,2)) ELSE 0 END), 0) AS DECIMAL(18,0)) AS TOTAL_ALL_VALUE_USD")
                )
                ->where('t1a.IV_WERKS_PARAM', $werks)
                ->whereIn('t1a.IV_AUART_PARAM', $auartList)
                ->whereRaw('CAST(t1a.QTY_BALANCE2 AS DECIMAL(18,3)) > 0')
                ->groupBy('t2a.KUNNR');

            // B) Agregat overdue value per customer (pisah USD & IDR)
            $overdueValueSubquery = DB::table('so_yppr079_t2 as t2_inner')
                ->join('so_yppr079_t1 as t1', function ($j) {
                    $j->on(DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2_inner.VBELN AS CHAR))'));
                })
                ->select(
                    't2_inner.KUNNR',
                    DB::raw("CAST(ROUND(SUM(CASE WHEN t1.WAERK = 'IDR' THEN CAST(t1.TOTPR AS DECIMAL(18,2)) ELSE 0 END), 0) AS DECIMAL(18,0)) AS TOTAL_OVERDUE_VALUE_IDR"),
                    DB::raw("CAST(ROUND(SUM(CASE WHEN t1.WAERK = 'USD' THEN CAST(t1.TOTPR AS DECIMAL(18,2)) ELSE 0 END), 0) AS DECIMAL(18,0)) AS TOTAL_OVERDUE_VALUE_USD")
                )
                ->where('t2_inner.IV_WERKS_PARAM', $werks)
                ->whereIn('t2_inner.IV_AUART_PARAM', $auartList)
                ->whereRaw('CAST(t1.QTY_BALANCE2 AS DECIMAL(18,3)) > 0')
                ->whereRaw("{$safeEdatuInner} < CURDATE()")
                ->groupBy('t2_inner.KUNNR');

            // Kueri utama
            $rows = DB::table('so_yppr079_t2 as t2')
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
                ->where('t2.IV_WERKS_PARAM', $werks)
                ->whereIn('t2.IV_AUART_PARAM', $auartList)
                ->whereExists(function ($q) use ($auartList) {
                    $q->select(DB::raw(1))
                        ->from('so_yppr079_t1 as t1_check')
                        ->whereColumn('t1_check.VBELN', 't2.VBELN')
                        ->whereIn('t1_check.IV_AUART_PARAM', $auartList)
                        ->where('t1_check.QTY_BALANCE2', '!=', 0);
                })
                ->whereNotNull('t2.NAME1')->where('t2.NAME1', '!=', '')
                ->groupBy('t2.KUNNR')
                ->orderBy('NAME1', 'asc')
                ->paginate(25)->withQueryString();
        }

        // 8) Performance details (agregat; gabungan Export+Replace bila perlu)
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
            )
            ->where('m.IV_WERKS', $werks)
            ->whereRaw('CAST(t1.QTY_BALANCE2 AS DECIMAL(18,3)) > 0'); // hanya outstanding item

        $safeEdatuPerf = "
COALESCE(
    STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
    STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
)";

        $inExportPerf = in_array($auart, $exportAuartCodes) && !in_array($auart, $replaceAuartCodes);
        $targetAuarts = $inExportPerf ? array_unique(array_merge($exportAuartCodes, $replaceAuartCodes)) : [$auart];

        $perf = (clone $performanceQueryBase)
            ->whereIn('m.IV_AUART', $targetAuarts)
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
        if ($perf && (int) ($perf->total_so ?? 0) > 0) {
            $performanceData->push((object) [
                'Deskription'      => $inExportPerf ? "KMI Export {$locationAbbr}" : ($descFromMap ?: $auart),
                'IV_WERKS'         => $werks,
                'IV_AUART'         => $auart, // tetap kirim AUART yang dipilih user (untuk API detail)
                'total_so'         => (int) $perf->total_so,
                'total_value_idr'  => (float) $perf->total_value_idr,
                'total_value_usd'  => (float) $perf->total_value_usd,
                'overdue_so_count' => (int) $perf->overdue_so_count,
                'overdue_1_30'     => (int) $perf->overdue_1_30,
                'overdue_31_60'    => (int) $perf->overdue_31_60,
                'overdue_61_90'    => (int) $perf->overdue_61_90,
                'overdue_over_90'  => (int) $perf->overdue_over_90,
            ]);
        }

        // 9) Small Quantity (≤5) by Customer — untuk grafik di PO Report
        $smallQtyByCustomer = collect();
        $totalSmallQtyOutstanding = 0;
        if ($show) {
            $smallQtyBase = DB::table('so_yppr079_t2 as t2')
                ->join(
                    'so_yppr079_t1 as t1',
                    DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                    '=',
                    DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
                )
                ->where('t2.IV_WERKS_PARAM', $werks)
                ->whereIn('t2.IV_AUART_PARAM', $auartList)
                ->whereRaw('CAST(t1.QTY_BALANCE2 AS DECIMAL(18,3)) > 0')
                ->whereRaw('CAST(t1.QTY_BALANCE2 AS DECIMAL(18,3)) <= 5')
                ->where('t1.QTY_GI', '>', 0); // hanya yang sudah pernah shipped

            $smallQtyByCustomer = (clone $smallQtyBase)
                // MODIFIKASI INI: Mengubah COUNT(t1.POSNR) menjadi COUNT(DISTINCT t2.VBELN)
                ->select('t2.NAME1', 't2.IV_WERKS_PARAM', DB::raw('COUNT(DISTINCT t2.VBELN) AS so_count'))
                ->groupBy('t2.NAME1', 't2.IV_WERKS_PARAM')
                ->orderBy('t2.NAME1')
                ->get();

            $totalSmallQtyOutstanding = (clone $smallQtyBase)->count('t1.POSNR');
        }

        // 10) Kirim ke view
        return view('po_report.po_report', [
            // mapping untuk nav pills (tanpa Replace) + sudah ada properti ->pill_label
            'mapping'  => $mappingForPills,
            'selected' => ['werks' => $werks, 'auart' => $auart],
            'selectedDescription' => $selectedDescription, // <- untuk judul/header aktif
            'rows'  => $rows,
            'compact'   => $compact,
            'show'  => $show,
            'performanceData' => $performanceData,
            'smallQtyByCustomer' => $smallQtyByCustomer,
            'totalSmallQtyOutstanding' => $totalSmallQtyOutstanding,
        ]);
    }



    /** Export item terpilih ke PDF/Excel */
    public function exportData(Request $request)
    {
        // Validasi dasar (tanpa memaksa integer di sini; kita sanitasi manual)
        $request->validate([
            'item_ids'       => 'required|array|min:1',
            'export_type' => 'required|string|in:pdf,excel',
            'werks'       => 'required|string',
            'auart'       => 'required|string',
        ]);

        // Sanitasi ID → hanya digit
        $ids = collect($request->input('item_ids', []))
            ->map(fn($v) => (int)preg_replace('/\D+/', '', (string)$v))
            ->filter(fn($v) => $v > 0)
            ->unique()->values()->all();

        if (empty($ids)) {
            return back()->withErrors('Tidak ada item yang valid untuk diekspor.');
        }

        $werks          = $request->input('werks');
        $auart          = $request->input('auart');
        $exportType = $request->input('export_type');

        // Ambil item by id + info header (PO, SO, Customer)
        $items = DB::table('so_yppr079_t1 as t1')
            ->leftJoin('so_yppr079_t2 as t2', 't1.VBELN', '=', 't2.VBELN')
            // >>> JOIN REMARK
            ->leftJoin('item_remarks_po as ir', function ($j) use ($werks, $auart) {
                $j->on('ir.VBELN', '=', 't1.VBELN')
                    ->on('ir.POSNR', '=', DB::raw("LPAD(TRIM(CAST(t1.POSNR AS CHAR)), 6, '0')"))
                    ->where('ir.IV_WERKS_PARAM', '=', $werks)
                    ->where('ir.IV_AUART_PARAM', '=', $auart);
            })
            ->select(
                't1.id',
                't1.VBELN as SO',
                't2.BSTNK as PO',
                't2.NAME1 as CUSTOMER',
                DB::raw("TRIM(LEADING '0' FROM t1.POSNR) AS POSNR"),
                DB::raw("CASE WHEN t1.MATNR REGEXP '^[0-9]+$' THEN TRIM(LEADING '0' FROM t1.MATNR) ELSE t1.MATNR END AS MATNR"),
                't1.MAKTX',
                't1.KWMENG',
                't1.QTY_GI',
                't1.QTY_BALANCE2',
                't1.KALAB',
                't1.KALAB2',
                't1.NETPR',
                't1.WAERK',
                // >>> TAMBAH INI
                DB::raw("COALESCE(ir.remark, '') as REMARK")
            )
            ->whereIn('t1.id', $ids)
            ->orderBy('t1.VBELN')->orderByRaw('CAST(t1.POSNR AS UNSIGNED)')
            ->get();

        if ($items->isEmpty()) {
            return back()->withErrors('Tidak ada item yang valid untuk diekspor.');
        }

        $locationMap     = ['2000' => 'Surabaya', '3000' => 'Semarang'];
        $locationName     = $locationMap[$werks] ?? $werks;
        $auartDesc     = DB::table('maping')->where('IV_WERKS', $werks)->where('IV_AUART', $auart)->value('Deskription');

        if ($exportType === 'excel') {
            $fileName = "PO_Items_{$locationName}_{$auart}_" . date('Ymd_His') . ".xlsx";
            return Excel::download(new PoItemsExport($items), $fileName);
        }

        // PDF
        $fileName = "PO_Items_{$locationName}_{$auart}_" . date('Ymd_His') . ".pdf";
        $pdf = Pdf::loadView('po_report.po_pdf_template', [
            'items'              => $items,
            'locationName'          => $locationName,
            'auartDescription'      => $auartDesc,
            'werks'              => $werks,
            'auart'              => $auart,
            'today'              => now(),
        ])
            ->setPaper('a4', 'landscape');

        return $pdf->stream($fileName);
    }

    // app/Http/Controllers/PoReportController.php

    /**
     * Mendapatkan data performance (KPI) berdasarkan KUNNR.
     * Digunakan untuk meng-update tabel performa saat customer di klik.
     */
    public function apiPerformanceByCustomer(Request $request)
    {
        $request->validate([
            'werks' => 'required|string',
            'auart' => 'required|string',
            'kunnr' => 'required|string', // Filter baru: Customer Number
        ]);

        $werks = $request->query('werks');
        $auart = $request->query('auart');
        $kunnr = $request->query('kunnr');

        // Pastikan KUNNR aman dari karakter non-digit jika diperlukan
        // $kunnr = preg_replace('/\D+/', '', (string)$kunnr);

        // LOGIKA PENGGABUNGAN Export + Replace (sama seperti di index)
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
        // END LOGIKA PENGGABUNGAN

        $safeEdatuPerf = "
        COALESCE(
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
        )";

        // Ambil Deskription untuk label di frontend
        $descForRow = $rawMapping
            ->where('IV_WERKS', $werks)
            ->where('IV_AUART', $auart)
            ->pluck('Deskription')
            ->first();

        // Kueri Performance yang difilter KUNNR
        $perfQuery = DB::table('so_yppr079_t2 as t2')
            ->join(
                'so_yppr079_t1 as t1',
                DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                '=',
                DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
            )
            ->where('t2.IV_WERKS_PARAM', $werks)
            ->whereIn('t2.IV_AUART_PARAM', $targetAuarts)
            ->whereRaw('CAST(t1.QTY_BALANCE2 AS DECIMAL(18,3)) > 0') // hanya outstanding item
            ->where('t2.KUNNR', $kunnr) // FILTER UTAMA KUNNR
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
                // Nama Deskripsi tetap diambil dari AUART yang sedang difilter (misalnya 'KMI Export')
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

        // Nama customer untuk sub-judul
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

    public function apiSavePoRemark(Request $request)
    {
        $validated = $request->validate([
            'werks'  => 'required|string',
            'auart'  => 'required|string',
            'vbeln'  => 'required|string',
            'posnr'  => 'required|string', // boleh "10", nanti dipad ke 6 digit
            'remark' => 'nullable|string|max:60',
        ]);

        $posnrDb    = str_pad(preg_replace('/\D/', '', (string)$validated['posnr']), 6, '0', STR_PAD_LEFT);
        $remarkText = trim($validated['remark'] ?? '');
        $userId     = Auth::id(); // bisa NULL kalau belum login

        $keys = [
            'IV_WERKS_PARAM' => $validated['werks'],
            'IV_AUART_PARAM' => $validated['auart'],
            'VBELN'          => $validated['vbeln'],
            'POSNR'          => $posnrDb,
        ];

        try {
            if ($remarkText === '') {
                DB::table('item_remarks_po')->where($keys)->delete();
            } else {
                // update dulu (supaya created_at tidak berubah); jika belum ada → insert
                $affected = DB::table('item_remarks_po')->where($keys)->update([
                    'remark'     => $remarkText,
                    'user_id'    => $userId,
                    'updated_at' => now(),
                ]);

                if ($affected === 0) {
                    DB::table('item_remarks_po')->insert($keys + [
                        'remark'     => $remarkText,
                        'user_id'    => $userId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            return response()->json(['ok' => true, 'message' => 'Catatan PO berhasil diproses.']);
        } catch (\Exception $e) {
            return response()->json(['ok' => false, 'message' => 'Gagal memproses catatan PO ke database.'], 500);
        }
    }
}
