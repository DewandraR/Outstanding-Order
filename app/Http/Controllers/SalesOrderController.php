<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\SoItemsExport;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Str;

class SalesOrderController extends Controller
{
    /**
     * Redirector untuk parameter terenkripsi.
     */
    public function redirector(Request $request)
    {
        $payload = $request->input('payload'); // string JSON dari JS
        $data = is_string($payload) ? json_decode($payload, true) : (array) $payload;
        abort_unless(is_array($data), 400, 'Invalid payload');

        // bersihkan field kosong
        $clean = array_filter($data, fn($v) => !is_null($v) && $v !== '');

        // redirect ke halaman utama SO dengan parameter terenkripsi
        return redirect()->route('so.index', ['q' => Crypt::encrypt($clean)]);
    }

    /**
     * Halaman Outstanding SO (report by customer).
     */
    public function index(Request $request)
    {
        // 1) decrypt q
        if ($request->filled('q')) {
            try {
                $data = Crypt::decrypt($request->query('q'));
                $request->merge($data);
            } catch (DecryptException $e) {
                abort(404);
            }
        }

        $werks = $request->query('werks');
        $auart = $request->query('auart');

        // 2) Mapping AUART mentah
        $rawMapping = DB::table('maping')
            ->select('IV_WERKS', 'IV_AUART', 'Deskription')
            ->orderBy('IV_WERKS')->orderBy('IV_AUART')
            ->get();

        // 3) Definisikan kode AUART untuk Export dan Replace
        $exportAuartCodes = $rawMapping
            ->filter(fn($i) => Str::contains(strtolower((string)$i->Deskription), 'export')
                && !Str::contains(strtolower((string)$i->Deskription), 'local')
                && !Str::contains(strtolower((string)$i->Deskription), 'replace'))
            ->pluck('IV_AUART')->unique()->toArray();

        $replaceAuartCodes = $rawMapping
            ->filter(fn($i) => Str::contains(strtolower((string)$i->Deskription), 'replace'))
            ->pluck('IV_AUART')->unique()->toArray();

        // Tentukan list AUART yang harus dikueri: jika auart saat ini adalah Export atau Replace, gabungkan keduanya.
        $auartList = [$auart];
        $isExportOrReplaceActive = in_array($auart, $exportAuartCodes) || in_array($auart, $replaceAuartCodes);
        if ($isExportOrReplaceActive) {
            $auartList = array_unique(array_merge($exportAuartCodes, $replaceAuartCodes));
        }

        // 4) LOGIC AUTO-PILIH DEFAULT AUART (Jika hanya plant yang dikirim)
        if ($request->filled('werks') && !$request->filled('auart') && !$request->has('q')) {
            $types = $rawMapping->where('IV_WERKS', $werks);

            // Prioritas 1: Export (yang sekarang juga mewakili Replace)
            $exportDefault = $types->first(function ($row) {
                $d = strtolower((string)$row->Deskription);
                return Str::contains($d, 'export') && !Str::contains($d, 'local');
            });

            // Prioritas 2: Local
            $localDefault = $types->first(function ($row) {
                $d = strtolower((string)$row->Deskription);
                return Str::contains($d, 'local');
            });

            $default = $exportDefault ?? $localDefault ?? $types->first();

            if ($default) {
                $payload = array_filter($request->except('q'), fn($v) => !is_null($v) && $v !== '');
                $payload['auart'] = trim($default->IV_AUART);
                return redirect()->route('so.index', ['q' => Crypt::encrypt($payload)]);
            }
        }

        // 5) Siapkan mapping untuk NAV PILLS (Gabungan Export dan Replace)
        $mappingForPills = $rawMapping
            ->map(function ($row) use ($exportAuartCodes, $replaceAuartCodes) {
                $descLower = strtolower((string)$row->Deskription);
                $isExport = Str::contains($descLower, 'export') && !Str::contains($descLower, 'local');
                $isLocal = Str::contains($descLower, 'local');
                $isReplace = Str::contains($descLower, 'replace');

                $abbr = $row->IV_WERKS === '3000' ? 'SMG' : ($row->IV_WERKS === '2000' ? 'SBY' : $row->IV_WERKS);

                // Gabungkan Export dan Replace ke dalam satu tombol "Export"
                if ($isExport) {
                    $row->pill_label = "KMI Export {$abbr}";
                } elseif ($isLocal) {
                    $row->pill_label = "KMI Local {$abbr}";
                } else {
                    $row->pill_label = $row->Deskription ?: $row->IV_AUART;
                }

                // Tandai mana yang harus dibuang dari list akhir, yaitu Replace
                $row->is_replace = $isReplace;

                return $row;
            })
            ->reject(fn($row) => $row->is_replace) // Buang Replace dari list navigasi
            ->groupBy('IV_WERKS')
            ->map(fn($g) => $g->unique('IV_AUART')->values());


        $rows = collect();
        $selectedDescription = '';
        $pageTotalsAll = [];
        $pageTotalsOverdue = [];
        $grandTotals = collect();
        $smallQtyByCustomer = collect(); // Inisialisasi

        $locationAbbr = $werks === '3000' ? 'SMG' : ($werks === '2000' ? 'SBY' : $werks);

        // Tentukan Selected Description (untuk header)
        if (in_array($auart, $exportAuartCodes)) {
            $selectedDescription = "KMI Export {$locationAbbr}";
        } elseif ($request->filled('auart')) {
            $descFromMap = $rawMapping->where('IV_WERKS', $werks)->where('IV_AUART', $auart)->pluck('Deskription')->first() ?? '';
            if (stripos((string)$descFromMap, 'local') !== false) {
                $selectedDescription = "KMI Local {$locationAbbr}";
            } else {
                $selectedDescription = $descFromMap;
            }
        }


        if ($werks && $auart) {
            // parser tanggal aman
            $safeEdatu = "COALESCE(
                STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
                STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
            )";
            $safeEdatuInner = "COALESCE(
                STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2_inner.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
                STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2_inner.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
            )";

            /** Subquery A: agregat SEMUA outstanding (qty & value) per customer, DIPISAH PER CURRENCY */
            $allAggSubquery = DB::table('so_yppr079_t1 as t1a')
                ->join('so_yppr079_t2 as t2a', 't2a.VBELN', '=', 't1a.VBELN')
                ->select(
                    't2a.KUNNR',
                    DB::raw('CAST(SUM(CAST(t1a.PACKG AS DECIMAL(18,3))) AS DECIMAL(18,3)) AS TOTAL_OUTS_QTY'),
                    // Split value ke IDR dan USD
                    DB::raw("CAST(SUM(CASE WHEN t1a.WAERK = 'IDR' THEN CAST(t1a.TOTPR2 AS DECIMAL(18,2)) ELSE 0 END) AS DECIMAL(18,2)) AS TOTAL_ALL_VALUE_IDR"),
                    DB::raw("CAST(SUM(CASE WHEN t1a.WAERK = 'USD' THEN CAST(t1a.TOTPR2 AS DECIMAL(18,2)) ELSE 0 END) AS DECIMAL(18,2)) AS TOTAL_ALL_VALUE_USD")
                )
                ->where('t1a.IV_WERKS_PARAM', $werks)
                ->whereIn('t1a.IV_AUART_PARAM', $auartList)
                ->whereRaw('CAST(t1a.PACKG AS DECIMAL(18,3)) > 0')
                ->groupBy('t2a.KUNNR');

            /** Subquery B: agregat hanya OVERDUE value per customer, DIPISAH PER CURRENCY */
            $overdueValueSubquery = DB::table('so_yppr079_t2 as t2_inner')
                ->join('so_yppr079_t1 as t1', function ($j) {
                    $j->on(DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2_inner.VBELN AS CHAR))'));
                })
                ->select(
                    't2_inner.KUNNR',
                    // Split value overdue ke IDR dan USD
                    DB::raw("CAST(SUM(CASE WHEN t1.WAERK = 'IDR' THEN CAST(t1.TOTPR2 AS DECIMAL(18,2)) ELSE 0 END) AS DECIMAL(18,2)) AS TOTAL_OVERDUE_VALUE_IDR"),
                    DB::raw("CAST(SUM(CASE WHEN t1.WAERK = 'USD' THEN CAST(t1.TOTPR2 AS DECIMAL(18,2)) ELSE 0 END) AS DECIMAL(18,2)) AS TOTAL_OVERDUE_VALUE_USD")
                )
                ->where('t2_inner.IV_WERKS_PARAM', $werks)
                ->whereIn('t2_inner.IV_AUART_PARAM', $auartList)
                ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) > 0')
                ->whereRaw("{$safeEdatuInner} < CURDATE()")
                ->groupBy('t2_inner.KUNNR');

            // Overview Customer (tabel utama)
            $rows = DB::table('so_yppr079_t2 as t2')
                ->leftJoinSub($allAggSubquery, 'agg_all', fn($j) => $j->on('t2.KUNNR', '=', 'agg_all.KUNNR'))
                ->leftJoinSub($overdueValueSubquery, 'agg_overdue', fn($j) => $j->on('t2.KUNNR', '=', 'agg_overdue.KUNNR'))
                ->select(
                    't2.KUNNR',
                    DB::raw('MAX(t2.NAME1) AS NAME1'),
                    // Total SO: Menghitung semua SO outstanding
                    DB::raw('COUNT(DISTINCT t2.VBELN) AS SO_TOTAL_COUNT'),
                    // Overdue SO: jumlah SO yang telat
                    DB::raw("COUNT(DISTINCT CASE WHEN {$safeEdatu} < CURDATE() THEN t2.VBELN ELSE NULL END) AS SO_LATE_COUNT"),
                    // Value dan Qty
                    DB::raw('COALESCE(MAX(agg_all.TOTAL_OUTS_QTY),0) AS TOTAL_OUTS_QTY'),
                    DB::raw('COALESCE(MAX(agg_all.TOTAL_ALL_VALUE_IDR),0) AS TOTAL_ALL_VALUE_IDR'),
                    DB::raw('COALESCE(MAX(agg_all.TOTAL_ALL_VALUE_USD),0) AS TOTAL_ALL_VALUE_USD'),
                    DB::raw('COALESCE(MAX(agg_overdue.TOTAL_OVERDUE_VALUE_IDR),0) AS TOTAL_OVERDUE_VALUE_IDR'),
                    DB::raw('COALESCE(MAX(agg_overdue.TOTAL_OVERDUE_VALUE_USD),0) AS TOTAL_OVERDUE_VALUE_USD')
                )
                ->where('t2.IV_WERKS_PARAM', $werks)
                ->whereIn('t2.IV_AUART_PARAM', $auartList)
                ->whereExists(function ($q) use ($auartList, $werks) {
                    $q->select(DB::raw(1))
                        ->from('so_yppr079_t1 as t1_check')
                        ->whereColumn('t1_check.VBELN', 't2.VBELN')
                        ->whereIn('t1_check.IV_AUART_PARAM', $auartList)
                        ->where('t1_check.IV_WERKS_PARAM', $werks)
                        ->where('t1_check.PACKG', '!=', 0);
                })
                ->whereNotNull('t2.NAME1')
                ->where('t2.NAME1', '!=', '')
                ->groupBy('t2.KUNNR')
                ->orderBy('NAME1', 'asc')
                ->get();

            // Total untuk footer
            $pageTotalsAll = [
                'USD' => $rows->sum('TOTAL_ALL_VALUE_USD'),
                'IDR' => $rows->sum('TOTAL_ALL_VALUE_IDR'),
            ];
            $pageTotalsOverdue = [
                'USD' => $rows->sum('TOTAL_OVERDUE_VALUE_USD'),
                'IDR' => $rows->sum('TOTAL_OVERDUE_VALUE_IDR'),
            ];
            $grandTotals = $pageTotalsOverdue; // Kompatibilitas lama

            // Mengambil Data Small Qty untuk tampilan awal Blade
            $smallQtyByCustomer = DB::table('so_yppr079_t1 as t1')
                ->join('so_yppr079_t2 as t2', DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'))
                ->where('t1.IV_WERKS_PARAM', $werks)
                ->whereIn('t1.IV_AUART_PARAM', $auartList)
                ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) > 0')
                ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) <= 5')
                // FILTER BARU: HANYA YANG Qty SO != Outs. SO
                ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) != CAST(t1.KWMENG AS DECIMAL(18,3))')
                ->groupBy('t2.NAME1', 't2.IV_WERKS_PARAM')
                // MODIFIKASI: Mengubah COUNT item menjadi COUNT SO unik
                ->selectRaw('t2.NAME1, t2.IV_WERKS_PARAM, COUNT(DISTINCT t1.VBELN) as so_count, COUNT(t1.POSNR) as item_count')
                ->orderBy('t2.NAME1')
                ->get();
        }

        // 6) data highlight
        $highlight = [
            'kunnr' => trim((string)$request->query('highlight_kunnr', '')),
            'vbeln' => trim((string)$request->query('highlight_vbeln', '')),
            'posnr' => trim((string)$request->query('highlight_posnr', '')),
        ];
        $autoExpand = $request->boolean('auto', !empty($highlight['kunnr']) && !empty($highlight['vbeln']));

        // 7) kirim ke view
        return view('sales_order.so_report', [
            'mapping'             => $mappingForPills, // Kirim mapping yang sudah difilter
            'rows'                => $rows,
            'selected'            => ['werks' => $werks, 'auart' => $auart],
            'selectedDescription' => $selectedDescription,
            'pageTotalsAll'       => $pageTotalsAll,
            'pageTotalsOverdue'   => $pageTotalsOverdue,
            'grandTotals'         => $grandTotals,
            'highlight'           => $highlight,
            'autoExpand'          => $autoExpand,
            'smallQtyByCustomer'  => $smallQtyByCustomer, // Kirim data Small Qty
        ]);
    }

    /**
     * API: Ambil daftar SO outstanding untuk 1 customer (Level 2).
     */
    public function apiGetSoByCustomer(Request $request)
    {
        // ... (Fungsi ini tidak berubah)
        // ... (Karena fungsi ini tidak melayani chart Small Qty, tidak perlu diubah)
        $request->validate([
            'kunnr' => 'required|string',
            'werks' => 'required|string',
            'auart' => 'required|string',
        ]);
        // ... (sisanya tidak berubah)
        $werks = $request->werks;
        $auart = $request->auart;

        // LOGIKA PENGGABUNGAN Export + Replace (diulang untuk API)
        $rawMapping = DB::table('maping')->get();
        $exportAuartCodes = $rawMapping
            ->filter(fn($i) => Str::contains(strtolower($i->Deskription), 'export')
                && !Str::contains(strtolower($i->Deskription), 'local')
                && !Str::contains(strtolower($i->Deskription), 'replace'))
            ->pluck('IV_AUART')->unique()->toArray();
        $replaceAuartCodes = $rawMapping
            ->filter(fn($i) => Str::contains(strtolower($i->Deskription), 'replace'))
            ->pluck('IV_AUART')->unique()->toArray();

        $auartList = [$auart];
        $isExportOrReplaceActive = in_array($auart, $exportAuartCodes) || in_array($auart, $replaceAuartCodes);
        if ($isExportOrReplaceActive) {
            $auartList = array_unique(array_merge($exportAuartCodes, $replaceAuartCodes));
        }
        // END LOGIKA PENGGABUNGAN

        // Subquery untuk menghitung item yang memiliki remark pada SO ini
        $remarksSub = DB::table('item_remarks as ir')
            ->join('so_yppr079_t1 as t1r', function ($j) {
                $j->on('t1r.IV_WERKS_PARAM', '=', 'ir.IV_WERKS_PARAM')
                    ->on('t1r.IV_AUART_PARAM', '=', 'ir.IV_AUART_PARAM')
                    ->on('t1r.VBELN', '=', 'ir.VBELN')
                    ->on('t1r.POSNR', '=', 'ir.POSNR');
            })
            ->where('ir.IV_WERKS_PARAM', $werks)
            ->whereIn('ir.IV_AUART_PARAM', $auartList)
            ->whereRaw("TRIM(COALESCE(ir.remark,'')) <> ''")
            ->whereRaw('CAST(t1r.PACKG AS DECIMAL(18,3)) <> 0')
            ->select('ir.VBELN', DB::raw('COUNT(*) AS remark_count'))
            ->groupBy('ir.VBELN');

        $rows = DB::table('so_yppr079_t1 as t1')
            ->leftJoin('so_yppr079_t2 as t2', 't1.VBELN', '=', 't2.VBELN')
            ->leftJoinSub($remarksSub, 'rk', fn($j) => $j->on('rk.VBELN', '=', 't1.VBELN'))
            ->select(
                't1.VBELN',
                't2.EDATU',
                't1.WAERK',
                // total value: semua outstanding (tidak hanya overdue)
                DB::raw('SUM(CAST(t1.TOTPR2 AS DECIMAL(18,2))) as total_value'),
                // outs qty: jumlah OUTS SO (PACKG) per SO
                DB::raw('SUM(CAST(t1.PACKG  AS DECIMAL(18,3))) as outs_qty'),
                DB::raw('COUNT(DISTINCT t1.id) as item_count'),
                DB::raw('COALESCE(MAX(rk.remark_count),0) AS remark_count')
            )
            ->where('t1.KUNNR', $request->kunnr)
            ->where('t1.IV_WERKS_PARAM', $werks)
            ->whereIn('t1.IV_AUART_PARAM', $auartList)
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) <> 0')
            ->groupBy('t1.VBELN', 't2.EDATU', 't1.WAERK')
            ->get();

        // hitung overdue & format tanggal
        $today = now()->startOfDay();
        foreach ($rows as $row) {
            $overdue = 0;
            $formattedEdatu = '';
            if (!empty($row->EDATU) && $row->EDATU !== '0000-00-00') {
                try {
                    $edatuDate = \Carbon\Carbon::parse($row->EDATU)->startOfDay();
                    $formattedEdatu = $edatuDate->format('d-m-Y');

                    $delta = $today->diffInDays($edatuDate, false);
                    if ($delta < 0) {
                        $overdue = abs($delta);
                    } elseif ($delta > 0) {
                        $overdue = -$delta;
                    } else {
                        $overdue = 0;
                    }
                } catch (\Exception $e) {
                }
            }
            $row->Overdue = $overdue;
            $row->FormattedEdatu = $formattedEdatu;
        }

        // sort
        $sorted = collect($rows)->sort(function ($a, $b) {
            $aOver = $a->Overdue > 0;
            $bOver = $b->Overdue > 0;
            if ($aOver !== $bOver) return $aOver ? -1 : 1; // overdue (+) di atas
            return $b->Overdue <=> $a->Overdue;          // desc di dalam grup
        })->values();

        return response()->json(['ok' => true, 'data' => $sorted], 200);
    }

    /**
     * API: Ambil item untuk 1 SO (Level 3), termasuk remark.
     */
    public function apiGetItemsBySo(Request $request)
    {
        // ... (Fungsi ini tidak berubah)
        // ... (Karena fungsi ini tidak melayani chart Small Qty, tidak perlu diubah)
        $request->validate([
            'vbeln' => 'required|string',
            'werks' => 'required|string',
            'auart' => 'required|string', // AUART yang aktif di nav pill (bisa Export atau Local)
        ]);

        $werks = $request->werks;
        $auart = $request->auart;

        // LOGIKA PENENTUAN AUART LIST UNTUK API LEVEL 3
        $rawMapping = DB::table('maping')->get();
        $exportAuartCodes = $rawMapping
            ->filter(fn($i) => Str::contains(strtolower($i->Deskription), 'export')
                && !Str::contains(strtolower($i->Deskription), 'local')
                && !Str::contains(strtolower($i->Deskription), 'replace'))
            ->pluck('IV_AUART')->unique()->toArray();
        $replaceAuartCodes = $rawMapping
            ->filter(fn($i) => Str::contains(strtolower($i->Deskription), 'replace'))
            ->pluck('IV_AUART')->unique()->toArray();

        $auartList = [$auart];
        $isExportOrReplaceActive = in_array($auart, $exportAuartCodes) || in_array($auart, $replaceAuartCodes);
        if ($isExportOrReplaceActive) {
            $auartList = array_unique(array_merge($exportAuartCodes, $replaceAuartCodes));
        }

        $items = DB::table('so_yppr079_t1 as t1')
            ->leftJoin('item_remarks as ir', function ($j) {
                $j->on('ir.IV_WERKS_PARAM', '=', 't1.IV_WERKS_PARAM')
                    ->on('ir.IV_AUART_PARAM', '=', 't1.IV_AUART_PARAM')
                    ->on('ir.VBELN', '=', 't1.VBELN')
                    ->on('ir.POSNR', '=', 't1.POSNR');
            })
            ->select(
                't1.id',
                't1.MAKTX',
                't1.KWMENG',
                't1.PACKG',
                't1.KALAB2',
                't1.DAYX',
                't1.ASSYM',
                't1.PAINT',
                't1.MENGE',
                't1.NETPR',
                't1.TOTPR2',
                't1.TOTPR',
                't1.NETWR',
                't1.WAERK',
                DB::raw("TRIM(LEADING '0' FROM TRIM(t1.POSNR)) as POSNR"),
                DB::raw("LPAD(TRIM(t1.POSNR), 6, '0') as POSNR_KEY"),
                DB::raw("CASE WHEN t1.MATNR REGEXP '^[0-9]+$' THEN TRIM(LEADING '0' FROM t1.MATNR) ELSE t1.MATNR END as MATNR"),
                't1.IV_WERKS_PARAM as WERKS_KEY',
                't1.IV_AUART_PARAM as AUART_KEY',
                't1.VBELN as VBELN_KEY',
                't1.POSNR as POSNR_KEY',
                'ir.remark'
            )
            ->where('t1.VBELN', $request->vbeln)
            ->where('t1.IV_WERKS_PARAM', $request->werks)
            ->whereIn('t1.IV_AUART_PARAM', $auartList) // **MENGGUNAKAN $auartList**
            ->where('t1.PACKG', '!=', 0)
            ->orderByRaw('CAST(t1.POSNR AS UNSIGNED) asc')
            ->get();

        return response()->json(['ok' => true, 'data' => $items]);
    }

    /**
     * Export PDF / Excel untuk item terpilih.
     */
    public function exportData(Request $request)
    {
        // ... (Fungsi ini tidak berubah)
        // ... (Fungsi ini tidak melayani chart Small Qty, tidak perlu diubah)
        $validated = $request->validate([
            'item_ids'      => 'required|array',
            'item_ids.*'    => 'integer',
            'export_type' => 'required|string|in:pdf,excel',
            'werks'       => 'required|string',
            'auart'       => 'required|string',
        ]);

        $itemIds    = $validated['item_ids'];
        $exportType = $validated['export_type'];
        $werks      = $validated['werks'];
        $auart      = $validated['auart'];

        $items = DB::table('so_yppr079_t1 as t1')
            ->leftJoin('item_remarks as ir', function ($j) {
                $j->on('ir.IV_WERKS_PARAM', '=', 't1.IV_WERKS_PARAM')
                    ->on('ir.IV_AUART_PARAM', '=', 't1.IV_AUART_PARAM')
                    ->on('ir.VBELN', '=', 't1.VBELN')
                    ->on('ir.POSNR', '=', 't1.POSNR');
            })
            ->whereIn('t1.id', $itemIds)
            ->select(
                't1.VBELN',
                DB::raw("TRIM(LEADING '0' FROM t1.POSNR) AS POSNR"),
                DB::raw("CASE WHEN t1.MATNR REGEXP '^[0-9]+$' THEN TRIM(LEADING '0' FROM t1.MATNR) ELSE t1.MATNR END AS MATNR"),
                't1.MAKTX',
                't1.KWMENG',
                't1.PACKG',
                't1.KALAB',
                't1.KALAB2',
                't1.ASSYM',
                't1.PAINT',
                't1.MENGE',
                'ir.remark'
            )
            ->orderBy('t1.VBELN', 'asc')
            ->orderByRaw('CAST(t1.POSNR AS UNSIGNED) asc')
            ->get();

        $locationMap    = ['2000' => 'Surabaya', '3000' => 'Semarang'];
        $locationName   = $locationMap[$werks] ?? $werks;
        $auartDesc      = DB::table('maping')->where('IV_WERKS', $werks)->where('IV_AUART', $auart)->value('Deskription');

        $vbelns = $items->pluck('VBELN')->unique();
        $headers = DB::table('so_yppr079_t2')
            ->whereIn('VBELN', $vbelns)
            ->select('VBELN', 'BSTNK', 'NAME1')
            ->get()
            ->keyBy('VBELN');

        foreach ($items as $item) {
            $item->headerInfo = $headers->get($item->VBELN);
        }

        $fileExtension = $exportType === 'excel' ? 'xlsx' : 'pdf';
        $fileName = "Outstanding_SO_{$locationName}_{$auart}_" . date('Ymd_His') . ".{$fileExtension}";

        if ($exportType === 'excel') {
            return Excel::download(new SoItemsExport($items), $fileName);
        }

        $dataForPdf = [
            'items'            => $items,
            'locationName'     => $locationName,
            'werks'            => $werks,
            'auartDescription' => $auartDesc,
            'auart'            => $auart,
        ];
        $pdf = Pdf::loadView('sales_order.so_pdf_template', $dataForPdf)
            ->setPaper('a4', 'landscape');

        return $pdf->stream($fileName);
    }

    /**
     * Simpan / hapus remark item.
     */
    public function apiSaveRemark(Request $request)
    {
        // ... (Fungsi ini tidak berubah)
        $validated = $request->validate([
            'werks'  => 'required|string',
            'auart'  => 'required|string',
            'vbeln'  => 'required|string',
            'posnr'  => 'required|string',
            'remark' => 'nullable|string|max:1000',
        ]);

        $remarkText = trim($validated['remark'] ?? '');

        try {
            if ($remarkText === '') {
                DB::table('item_remarks')->where([
                    'IV_WERKS_PARAM' => $validated['werks'],
                    'IV_AUART_PARAM' => $validated['auart'],
                    'VBELN'          => $validated['vbeln'],
                    'POSNR'          => $validated['posnr'],
                ])->delete();
            } else {
                DB::table('item_remarks')->updateOrInsert(
                    [
                        'IV_WERKS_PARAM' => $validated['werks'],
                        'IV_AUART_PARAM' => $validated['auart'],
                        'VBELN'          => $validated['vbeln'],
                        'POSNR'          => $validated['posnr'],
                    ],
                    [
                        'remark'     => $remarkText,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
            return response()->json(['ok' => true, 'message' => 'Catatan berhasil diproses.']);
        } catch (\Exception $e) {
            return response()->json(['ok' => false, 'message' => 'Gagal memproses catatan ke database.'], 500);
        }
    }

    public function exportCustomerSummary(Request $request)
    {
        // ... (Fungsi ini tidak berubah)
        // ... (Fungsi ini tidak melayani chart Small Qty, tidak perlu diubah)
        if ($request->filled('q')) {
            try {
                $data = Crypt::decrypt($request->query('q'));
                $request->merge($data);
            } catch (DecryptException $e) {
                abort(404);
            }
        }
        $request->validate([
            'werks' => 'required|string',
            'auart' => 'required|string',
        ]);

        $werks = $request->query('werks');
        $auart = $request->query('auart');

        // Logika Penggabungan Export/Replace
        $rawMapping = DB::table('maping')->get();
        $exportAuartCodes = $rawMapping
            ->filter(fn($i) => Str::contains(strtolower($i->Deskription), 'export')
                && !Str::contains(strtolower($i->Deskription), 'local')
                && !Str::contains(strtolower($i->Deskription), 'replace'))
            ->pluck('IV_AUART')->unique()->toArray();
        $replaceAuartCodes = $rawMapping
            ->filter(fn($i) => Str::contains(strtolower($i->Deskription), 'replace'))
            ->pluck('IV_AUART')->unique()->toArray();

        $auartList = [$auart];
        $isExportOrReplaceActive = in_array($auart, $exportAuartCodes) || in_array($auart, $replaceAuartCodes);
        if ($isExportOrReplaceActive) {
            $auartList = array_unique(array_merge($exportAuartCodes, $replaceAuartCodes));
        }
        // End Logika Penggabungan

        // Deskripsi SO Type & lokasi
        $locationMap    = ['2000' => 'Surabaya', '3000' => 'Semarang'];
        $locationName   = $locationMap[$werks] ?? $werks;
        $auartDesc      = DB::table('maping')->where('IV_WERKS', $werks)->where('IV_AUART', $auart)->value('Deskription');

        // Aman-kan parsing tanggal
        $safeEdatu = "COALESCE(STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'), STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y'))";
        $safeEdatuInner = "COALESCE(STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2_inner.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'), STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2_inner.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y'))";

        // Total value OVERDUE per customer
        $overdueValueSubquery = DB::table('so_yppr079_t2 as t2_inner')
            ->join('so_yppr079_t1 as t1', function ($j) {
                $j->on(DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2_inner.VBELN AS CHAR))'));
            })
            ->select(
                't2_inner.KUNNR',
                DB::raw('CAST(SUM(CAST(t1.TOTPR2 AS DECIMAL(18,2))) AS DECIMAL(18,2)) AS TOTAL_OVERDUE_VALUE'),
                DB::raw('MAX(t1.WAERK) as WAERK')
            )
            ->where('t2_inner.IV_WERKS_PARAM', $werks)
            ->whereIn('t2_inner.IV_AUART_PARAM', $auartList)
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) > 0')
            ->whereRaw("{$safeEdatuInner} < CURDATE()")
            ->groupBy('t2_inner.KUNNR');

        $rows = DB::table('so_yppr079_t2 as t2')
            ->leftJoinSub($overdueValueSubquery, 'overdue_values', fn($j) => $j->on('t2.KUNNR', '=', 'overdue_values.KUNNR'))
            ->select(
                't2.KUNNR',
                DB::raw('MAX(t2.NAME1) AS NAME1'),
                DB::raw('MAX(t2.WAERK) AS WAERK'),
                DB::raw('COALESCE(MAX(overdue_values.TOTAL_OVERDUE_VALUE), 0) AS TOTAL_VALUE'),
                DB::raw("COUNT(DISTINCT CASE WHEN {$safeEdatu} < CURDATE() THEN t2.VBELN ELSE NULL END) AS SO_LATE_COUNT")
            )
            ->where('t2.IV_WERKS_PARAM', $werks)
            ->whereIn('t2.IV_AUART_PARAM', $auartList)
            ->whereExists(function ($query) use ($auartList, $werks) {
                $query->select(DB::raw(1))
                    ->from('so_yppr079_t1 as t1_check')
                    ->whereColumn('t1_check.VBELN', 't2.VBELN')
                    ->whereIn('t1_check.IV_AUART_PARAM', $auartList)
                    ->where('t1_check.IV_WERKS_PARAM', $werks)
                    ->where('t1_check.PACKG', '!=', 0);
            })
            ->whereNotNull('t2.NAME1')->where('t2.NAME1', '!=', '')
            ->groupBy('t2.KUNNR')->orderBy('NAME1', 'asc')->get();

        $totals = $rows->groupBy('WAERK')->map(fn($g) => $g->sum('TOTAL_VALUE'));

        $data = [
            'rows'             => $rows,
            'totals'           => $totals,
            'locationName'     => $locationName,
            'werks'            => $werks,
            'auartDescription' => $auartDesc,
            'today'            => now(),
        ];

        $fileName = "Overview_Customer_{$locationName}_{$auart}_" . date('Ymd_His') . ".pdf";

        $pdf = Pdf::loadView('sales_order.so_customer_summary_pdf', $data)->setPaper('a4', 'landscape');

        return $pdf->stream($fileName);
    }

    /**
     * API: Small Quantity (≤5) Outstanding SO Items by Customer — untuk chart ringkas.
     * Definisi: PACKG (Outs. SO) > 0 dan ≤ 5 DAN PACKG != KWMENG
     */
    public function apiSmallQtyByCustomer(Request $request)
    {
        // Param mengikuti halaman SO Report: werks & auart
        $request->validate(['werks' => 'required|string', 'auart' => 'required|string']);
        $werks = $request->query('werks');
        $auart = $request->query('auart');

        // Gabung Export + Replace bila auart aktif termasuk salah satunya
        $auartList = $this->resolveAuartListForContext($auart);

        // Kriteria small qty untuk SO: PACKG (Outstanding SO) 1..5
        $rows = DB::table('so_yppr079_t1 as t1')
            ->join('so_yppr079_t2 as t2', DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'))
            ->where('t1.IV_WERKS_PARAM', $werks)
            ->whereIn('t1.IV_AUART_PARAM', $auartList)
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) > 0')
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) <= 5')
            // FILTER BARU: Outs. SO != Qty SO (Item sudah pernah dikirim)
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) != CAST(t1.KWMENG AS DECIMAL(18,3))')
            ->groupBy('t2.NAME1', 't2.IV_WERKS_PARAM')
            // MODIFIKASI: Mengubah COUNT item menjadi COUNT SO unik
            ->selectRaw('t2.NAME1, t2.IV_WERKS_PARAM, COUNT(DISTINCT t1.VBELN) as so_count')
            ->orderBy('t2.NAME1')
            ->get();

        return response()->json([
            'ok'   => true,
            'data' => $rows,
            'meta' => ['werks' => $werks, 'auart' => $auart],
        ]);
    }

    /**
     * API: Detail item Small Quantity (≤5) Outstanding per Customer untuk tabel.
     */
    public function apiSmallQtyDetails(Request $request)
    {
        // ... (Logika di sini juga harus ditambahkan filter baru)
        $request->validate([
            'customerName' => 'required|string',
            'werks' => 'required|string',
            'auart' => 'required|string',
        ]);

        $customerName = $request->query('customerName');
        $werks = $request->query('werks');
        $auart = $request->query('auart');

        // Gabung Export + Replace bila perlu
        $auartList = $this->resolveAuartListForContext($auart);

        // Ambil daftar item small qty (PACKG 1..5) untuk customer + werks + konteks auart
        $items = DB::table('so_yppr079_t1 as t1')
            ->join('so_yppr079_t2 as t2', DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'))
            ->where('t2.NAME1', $customerName)
            ->where('t1.IV_WERKS_PARAM', $werks)
            ->whereIn('t1.IV_AUART_PARAM', $auartList)
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) > 0')
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) <= 5')
            // FILTER BARU: Outs. SO != Qty SO (Item sudah pernah dikirim)
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) != CAST(t1.KWMENG AS DECIMAL(18,3))')
            ->select(
                DB::raw('TRIM(CAST(t2.VBELN AS CHAR)) as SO'),
                DB::raw("TRIM(LEADING '0' FROM t1.POSNR) as POSNR"),
                DB::raw("CASE WHEN t1.MATNR REGEXP '^[0-9]+$' THEN TRIM(LEADING '0' FROM t1.MATNR) ELSE t1.MATNR END as MATNR"),
                't1.MAKTX',
                't1.KWMENG',
                't1.PACKG'
            )
            ->orderBy('t2.VBELN', 'asc')->orderByRaw('LPAD(TRIM(CAST(t1.POSNR AS CHAR)), 6, "0")')->get();

        return response()->json(['ok' => true, 'data' => $items]);
    }

    /**
     * Export PDF Small Quantity (≤5) Outstanding per Customer.
     */
    public function exportSmallQtyPdf(Request $request)
    {
        // ... (Logika di sini juga harus ditambahkan filter baru)
        $validated = $request->validate([
            'customerName' => 'required|string',
            'werks' => 'required|string',
            'auart' => 'required|string',
        ]);

        $customerName = $validated['customerName'];
        $werks = $validated['werks'];
        $auart = $validated['auart'];

        // Gabung Export + Replace bila perlu
        $auartList = $this->resolveAuartListForContext($auart);

        $items = DB::table('so_yppr079_t1 as t1')
            ->join('so_yppr079_t2 as t2', DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2.VBELN AS CHAR))'))
            ->where('t2.NAME1', $customerName)
            ->where('t1.IV_WERKS_PARAM', $werks)
            ->whereIn('t1.IV_AUART_PARAM', $auartList)
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) > 0')
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) <= 5')
            // FILTER BARU: Outs. SO != Qty SO (Item sudah pernah dikirim)
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) != CAST(t1.KWMENG AS DECIMAL(18,3))')
            ->select(
                DB::raw('TRIM(CAST(t2.VBELN AS CHAR)) AS SO'),
                DB::raw('TRIM(LEADING "0" FROM t1.POSNR) AS POSNR'),
                DB::raw("CASE WHEN t1.MATNR REGEXP '^[0-9]+$' THEN TRIM(LEADING '0' FROM t1.MATNR) ELSE t1.MATNR END AS MATNR"),
                't1.MAKTX',
                't1.KWMENG',
                't1.PACKG'
            )
            ->orderBy('t2.VBELN', 'asc')->orderByRaw('LPAD(TRIM(CAST(t1.POSNR AS CHAR)), 6, "0")')->get();

        $locationMap  = ['2000' => 'Surabaya', '3000' => 'Semarang'];
        $locationName = $locationMap[$werks] ?? $werks;

        $pdf = Pdf::loadView('sales_order.small-qty-pdf', [
            'items'        => $items,
            'customerName' => $customerName,
            'locationName' => $locationName,
            'generatedAt'  => now()->format('d-m-Y'),
        ])->setPaper('a4', 'portrait');

        $filename = 'SO_SmallQty_' . $locationName . '_' . Str::slug($customerName) . '.pdf';
        return $pdf->stream($filename);
    }

    /**
     * Helper: Resolusi AUART list (gabungkan Export + Replace saat konteks Export/Replace aktif)
     */
    private function resolveAuartListForContext(?string $auart): array
    {
        $rawMapping = DB::table('maping')->get();

        $exportAuartCodes = $rawMapping
            ->filter(fn($i) => Str::contains(strtolower((string)$i->Deskription), 'export')
                && !Str::contains(strtolower((string)$i->Deskription), 'local')
                && !Str::contains(strtolower((string)$i->Deskription), 'replace'))
            ->pluck('IV_AUART')->unique()->toArray();

        $replaceAuartCodes = $rawMapping
            ->filter(fn($i) => Str::contains(strtolower((string)$i->Deskription), 'replace'))
            ->pluck('IV_AUART')->unique()->toArray();

        $list = $auart ? [$auart] : [];
        $isExportOrReplaceActive = $auart && (in_array($auart, $exportAuartCodes) || in_array($auart, $replaceAuartCodes));
        if ($isExportOrReplaceActive) {
            $list = array_unique(array_merge($exportAuartCodes, $replaceAuartCodes));
        }
        return $list ?: ($auart ? [$auart] : []);
    }
}
