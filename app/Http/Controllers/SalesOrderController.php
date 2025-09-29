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

class SalesOrderController extends Controller
{
    // use Illuminate\Http\Request;
    // use Illuminate\Support\Facades\Crypt;

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
     * Halaman Outstanding SO (report by customer) — TANPA paginate.
     */
    public function index(Request $request)
    {
        // 1) Terima q terenkripsi lalu merge ke query
        if ($request->filled('q')) {
            try {
                $data = Crypt::decrypt($request->query('q'));
                $request->merge($data);
            } catch (DecryptException $e) {
                abort(404);
            }
        }

        // 2) Jika pilih plant tapi belum ada auart → redirect ke default (pakai q terenkripsi)
        if ($request->filled('werks') && !$request->filled('auart')) {
            $mapping = DB::table('maping')
                ->select('IV_WERKS', 'IV_AUART', 'Deskription')
                ->where('IV_WERKS', $request->werks)
                ->orderBy('IV_AUART')
                ->get();

            $defaultType = $mapping->first(function ($item) {
                return str_contains(strtolower($item->Deskription), 'export');
            }) ?? $mapping->first();

            if ($defaultType) {
                $payload = array_filter($request->except('q'), fn($v) => !is_null($v) && $v !== '');
                $payload['auart'] = trim($defaultType->IV_AUART);

                return redirect()->route('so.index', ['q' => Crypt::encrypt($payload)]);
            }
        }

        // 3) Baca filter utama
        $werks = $request->query('werks');
        $auart = $request->query('auart');

        // 4) Data untuk pills (SO Type per plant)
        $mapping = DB::table('maping')
            ->select('IV_WERKS', 'IV_AUART', 'Deskription')
            ->orderBy('IV_WERKS')->orderBy('IV_AUART')
            ->get()
            ->groupBy('IV_WERKS')
            ->map(fn($g) => $g->unique('IV_AUART')->values());

        $rows = collect();
        $selectedDescription = '';
        $pageTotals = collect();
        $grandTotals = collect();

        if ($werks && $auart) {
            // Deskripsi tipe terpilih
            $selectedMapping = DB::table('maping')
                ->where('IV_WERKS', $werks)
                ->where('IV_AUART', $auart)
                ->first();
            $selectedDescription = $selectedMapping->Deskription ?? '';

            // Parser tanggal aman
            $safeEdatu = "COALESCE(
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
        )";

            $safeEdatuInner = "COALESCE(
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2_inner.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
            STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2_inner.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
        )";

            // Total value overdue per customer (hanya PACKG ≠ 0)
            $overdueValueSubquery = DB::table('so_yppr079_t2 as t2_inner')
                ->join('so_yppr079_t1 as t1', function ($j) {
                    $j->on(DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2_inner.VBELN AS CHAR))'));
                })
                ->select(
                    't2_inner.KUNNR',
                    DB::raw('CAST(SUM(CAST(t1.TOTPR2 AS DECIMAL(18,2))) AS DECIMAL(18,2)) AS TOTAL_OVERDUE_VALUE')
                )
                ->where('t2_inner.IV_WERKS_PARAM', $werks)
                ->where('t2_inner.IV_AUART_PARAM', $auart)
                ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) > 0')                // hanya outstanding
                ->whereRaw("{$safeEdatuInner} < CURDATE()")                       // hanya overdue
                ->groupBy('t2_inner.KUNNR');

            // Overview Customer (tanpa paginate)
            $rows = DB::table('so_yppr079_t2 as t2')
                ->leftJoinSub(
                    $overdueValueSubquery,
                    'overdue_values',
                    fn($join) =>
                    $join->on('t2.KUNNR', '=', 'overdue_values.KUNNR')
                )
                ->select(
                    't2.KUNNR',
                    DB::raw('MAX(t2.NAME1) AS NAME1'),
                    DB::raw('MAX(t2.WAERK) AS WAERK'),
                    DB::raw('COALESCE(MAX(overdue_values.TOTAL_OVERDUE_VALUE), 0) AS TOTAL_VALUE'),
                    DB::raw("COUNT(DISTINCT CASE WHEN {$safeEdatu} < CURDATE() THEN t2.VBELN ELSE NULL END) AS SO_LATE_COUNT"),
                    DB::raw("
                    ROUND(
                        (COUNT(DISTINCT CASE WHEN {$safeEdatu} < CURDATE() THEN t2.VBELN ELSE NULL END)
                         / NULLIF(COUNT(DISTINCT t2.VBELN),0)) * 100, 2
                    ) AS LATE_PCT
                ")
                )
                ->where('t2.IV_WERKS_PARAM', $werks)
                ->where('t2.IV_AUART_PARAM', $auart)
                ->whereExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('so_yppr079_t1 as t1_check')
                        ->whereColumn('t1_check.VBELN', 't2.VBELN')
                        ->where('t1_check.PACKG', '!=', 0);
                })
                ->whereNotNull('t2.NAME1')
                ->where('t2.NAME1', '!=', '')
                ->groupBy('t2.KUNNR')
                ->orderBy('NAME1', 'asc')
                ->get();

            // Total per mata uang (juga grand total karena no paginate)
            $pageTotals = $rows->groupBy('WAERK')->map(fn($g) => $g->sum('TOTAL_VALUE'));
            $grandTotals = $pageTotals;
        }

        // 5) Data highlight untuk auto-expand sampai tabel-3
        $highlight = [
            'kunnr' => trim((string)$request->query('highlight_kunnr', '')),
            'vbeln' => trim((string)$request->query('highlight_vbeln', '')),
            'posnr' => trim((string)$request->query('highlight_posnr', '')), // opsional
        ];
        $autoExpand = $request->boolean(
            'auto',
            !empty($highlight['kunnr']) && !empty($highlight['vbeln'])
        );

        // 6) Kirim ke view
        return view('sales_order.so_report', [
            'mapping'             => $mapping,
            'rows'                => $rows,
            'selected'            => ['werks' => $werks, 'auart' => $auart],
            'selectedDescription' => $selectedDescription,
            'pageTotals'          => $pageTotals,
            'grandTotals'         => $grandTotals,
            // ⬇️ penting untuk auto-expand
            'highlight'           => $highlight,
            'autoExpand'          => $autoExpand,
        ]);
    }

    /**
     * API: Ambil daftar SO outstanding untuk 1 customer (Level 2).
     */
    public function apiGetSoByCustomer(Request $request)
    {
        $request->validate([
            'kunnr' => 'required|string',
            'werks' => 'required|string',
            'auart' => 'required|string',
        ]);

        $werks = $request->werks;
        $auart = $request->auart;

        // Subquery: hitung jumlah remark per SO (hanya untuk item outstanding / PACKG != 0)
        $remarksSub = DB::table('item_remarks as ir')
            ->join('so_yppr079_t1 as t1r', function ($j) {
                $j->on('t1r.IV_WERKS_PARAM', '=', 'ir.IV_WERKS_PARAM')
                    ->on('t1r.IV_AUART_PARAM', '=', 'ir.IV_AUART_PARAM')
                    ->on('t1r.VBELN', '=', 'ir.VBELN')
                    ->on('t1r.POSNR', '=', 'ir.POSNR');
            })
            ->where('ir.IV_WERKS_PARAM', $werks)
            ->where('ir.IV_AUART_PARAM', $auart)
            ->whereRaw("TRIM(COALESCE(ir.remark,'')) <> ''")
            ->whereRaw('CAST(t1r.PACKG AS DECIMAL(18,3)) <> 0') // konsisten dg filter item Anda
            ->select('ir.VBELN', DB::raw('COUNT(*) AS remark_count'))
            ->groupBy('ir.VBELN');

        $rows = DB::table('so_yppr079_t1 as t1')
            ->leftJoin('so_yppr079_t2 as t2', 't1.VBELN', '=', 't2.VBELN')
            ->leftJoinSub($remarksSub, 'rk', function ($join) {
                $join->on('rk.VBELN', '=', 't1.VBELN');
            })
            ->select(
                't1.VBELN',
                't2.EDATU',
                't1.WAERK',
                DB::raw('SUM(t1.TOTPR2) as total_value'),
                DB::raw('COUNT(DISTINCT t1.id) as item_count'),
                // <-- kolom baru untuk FE Tabel-2
                DB::raw('COALESCE(MAX(rk.remark_count), 0) AS remark_count')
            )
            ->where('t1.KUNNR', $request->kunnr)
            ->where('t1.IV_WERKS_PARAM', $werks)
            ->where('t1.IV_AUART_PARAM', $auart)
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) <> 0') // hanya outstanding
            ->groupBy('t1.VBELN', 't2.EDATU', 't1.WAERK')
            ->get();

        // Hitung overdue seperti sebelumnya
        $today = now()->startOfDay();
        foreach ($rows as $row) {
            $overdue = 0;
            $formattedEdatu = '';
            if (!empty($row->EDATU) && $row->EDATU !== '0000-00-00') {
                try {
                    $edatuDate = \Carbon\Carbon::parse($row->EDATU)->startOfDay();
                    $formattedEdatu = $edatuDate->format('d-m-Y');
                    $overdue = $today->diffInDays($edatuDate, false); // negatif = telat
                } catch (\Exception $e) { /* ignore */
                }
            }
            $row->Overdue        = $overdue;
            $row->FormattedEdatu = $formattedEdatu;
        }

        // Urutkan dari yang paling telat
        return response()->json([
            'ok'   => true,
            'data' => collect($rows)->sortBy('Overdue')->values(),
        ]);
    }

    /**
     * API: Ambil item untuk 1 SO (Level 3), termasuk remark.
     */
    public function apiGetItemsBySo(Request $request)
    {
        $request->validate([
            'vbeln' => 'required|string',
            'werks' => 'required|string',
            'auart' => 'required|string',
        ]);

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
                // kunci natural untuk FE
                't1.IV_WERKS_PARAM as WERKS_KEY',
                't1.IV_AUART_PARAM as AUART_KEY',
                't1.VBELN as VBELN_KEY',
                't1.POSNR as POSNR_KEY',
                // remark
                'ir.remark'
            )
            ->where('t1.VBELN', $request->vbeln)
            ->where('t1.IV_WERKS_PARAM', $request->werks)
            ->where('t1.IV_AUART_PARAM', $request->auart)
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
        $validated = $request->validate([
            'item_ids'    => 'required|array',
            'item_ids.*'  => 'integer',
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

        $locationMap   = ['2000' => 'Surabaya', '3000' => 'Semarang'];
        $locationName  = $locationMap[$werks] ?? $werks;
        $auartDesc     = DB::table('maping')->where('IV_WERKS', $werks)->where('IV_AUART', $auart)->value('Deskription');

        $vbelns  = $items->pluck('VBELN')->unique();
        // Tambahkan NAME1 agar bisa cetak nama customer di kolom paling kiri
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

        // PDF
        $dataForPdf = [
            'items'           => $items,
            'locationName'    => $locationName,
            'werks'           => $werks,
            'auartDescription' => $auartDesc,
            'auart'           => $auart,
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
        if ($request->filled('q')) {
            try {
                $data = Crypt::decrypt($request->query('q'));
                // jadikan seperti query biasa agar Blade/JS gampang pakai
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

        // Deskripsi SO Type & lokasi
        $locationMap   = ['2000' => 'Surabaya', '3000' => 'Semarang'];
        $locationName  = $locationMap[$werks] ?? $werks;
        $auartDesc     = DB::table('maping')->where('IV_WERKS', $werks)->where('IV_AUART', $auart)->value('Deskription');

        // Aman-kan parsing tanggal
        $safeEdatu = "COALESCE(
        STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
        STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
    )";
        $safeEdatuInner = "COALESCE(
        STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2_inner.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
        STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2_inner.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
    )";

        // Total value OVERDUE per customer
        $overdueValueSubquery = DB::table('so_yppr079_t2 as t2_inner')
            ->join('so_yppr079_t1 as t1', function ($j) {
                $j->on(DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'), '=', DB::raw('TRIM(CAST(t2_inner.VBELN AS CHAR))'));
            })
            ->select(
                't2_inner.KUNNR',
                DB::raw('CAST(SUM(CAST(t1.TOTPR2 AS DECIMAL(18,2))) AS DECIMAL(18,2)) AS TOTAL_OVERDUE_VALUE')
            )
            ->where('t2_inner.IV_WERKS_PARAM', $werks)
            ->where('t2_inner.IV_AUART_PARAM', $auart)
            ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) > 0')
            ->whereRaw("{$safeEdatuInner} < CURDATE()")
            ->groupBy('t2_inner.KUNNR');

        // Ambil semua baris overview (tanpa paginate)
        $rows = DB::table('so_yppr079_t2 as t2')
            ->leftJoinSub($overdueValueSubquery, 'overdue_values', function ($join) {
                $join->on('t2.KUNNR', '=', 'overdue_values.KUNNR');
            })
            ->select(
                't2.KUNNR',
                DB::raw('MAX(t2.NAME1) AS NAME1'),
                DB::raw('MAX(t2.WAERK) AS WAERK'),
                DB::raw('COALESCE(MAX(overdue_values.TOTAL_OVERDUE_VALUE), 0) AS TOTAL_VALUE'),
                DB::raw("COUNT(DISTINCT CASE WHEN {$safeEdatu} < CURDATE() THEN t2.VBELN ELSE NULL END) as SO_LATE_COUNT"),
                DB::raw("
                ROUND(
                    (COUNT(DISTINCT CASE WHEN {$safeEdatu} < CURDATE() THEN t2.VBELN ELSE NULL END) / COUNT(DISTINCT t2.VBELN)) * 100, 2
                ) as LATE_PCT
            ")
            )
            ->where('t2.IV_WERKS_PARAM', $werks)
            ->where('t2.IV_AUART_PARAM', $auart)
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('so_yppr079_t1 as t1_check')
                    ->whereColumn('t1_check.VBELN', 't2.VBELN')
                    ->where('t1_check.PACKG', '!=', 0);
            })
            ->whereNotNull('t2.NAME1')
            ->where('t2.NAME1', '!=', '')
            ->groupBy('t2.KUNNR')
            ->orderBy('NAME1', 'asc')
            ->get();

        // Total per currency (untuk footer)
        $totals = $rows->groupBy('WAERK')->map(fn($g) => $g->sum('TOTAL_VALUE'));

        $data = [
            'rows'            => $rows,
            'totals'          => $totals,
            'locationName'    => $locationName,
            'werks'           => $werks,
            'auartDescription' => $auartDesc,
            'today'           => now(),
        ];

        $fileName = "Overview_Customer_{$locationName}_{$auart}_" . date('Ymd_His') . ".pdf";

        $pdf = Pdf::loadView('sales_order.so_customer_summary_pdf', $data)
            ->setPaper('a4', 'landscape'); // landscape agar mirip UI

        return $pdf->stream($fileName);
    }
}
