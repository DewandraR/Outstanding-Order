<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel; // 🟢 FIX: Mengaktifkan Facade Excel
use App\Exports\StockItemsExport;   // 🟢 FIX: Mengimpor Export Class

class StockController extends Controller
{
    public function index(Request $request)
    {
        // ⬅️ DECRYPT ?q jika ada
        if ($request->filled('q')) {
            try {
                $data = Crypt::decrypt($request->query('q'));
                $request->merge(is_array($data) ? $data : []);
            } catch (DecryptException $e) {
                abort(404);
            }
        }

        $mapping = DB::table('maping')
            ->select('IV_WERKS', 'IV_AUART', 'Deskription')
            ->orderBy('IV_WERKS')->orderBy('IV_AUART')
            ->get()
            ->groupBy('IV_WERKS')
            ->map(fn($g) => $g->unique('IV_AUART')->values());

        $werks = $request->query('werks');

        // ⬅️ redirect default TYPE tapi dalam bentuk terenkripsi
        if ($werks && !$request->has('type')) {
            $enc = Crypt::encrypt(['werks' => $werks, 'type' => 'whfg']);
            return redirect()->route('stock.index', ['q' => $enc]);
        }

        $type = $request->query('type') === 'fg' ? 'fg' : 'whfg';

        $pillTotals = ['whfg_qty' => 0, 'fg_qty' => 0];
        if ($werks) {
            $pillTotals['whfg_qty'] = (float) DB::table('so_yppr079_t1')
                ->where('IV_WERKS_PARAM', $werks)
                ->where('KALAB', '>', 0)
                ->sum('KALAB');

            $pillTotals['fg_qty'] = (float) DB::table('so_yppr079_t1')
                ->where('IV_WERKS_PARAM', $werks)
                ->where('KALAB2', '>', 0)
                ->sum('KALAB2');
        }

        $rows = null;
        $grandTotalQty = 0;
        $grandTotalsCurr = [];

        if ($werks && $type) {
            $q = DB::table('so_yppr079_t1 as t1')
                ->where('t1.IV_WERKS_PARAM', $werks)
                ->whereNotNull('t1.NAME1')->where('t1.NAME1', '!=', '');

            if ($type === 'whfg') {
                $q->where('t1.KALAB', '>', 0)
                    ->select(
                        't1.KUNNR',
                        't1.NAME1',
                        't1.WAERK',
                        DB::raw('SUM(t1.NETPR * t1.KALAB) AS TOTAL_VALUE'),
                        DB::raw('COUNT(DISTINCT t1.VBELN) AS SO_COUNT'),
                        DB::raw('SUM(t1.KALAB) AS TOTAL_QTY')
                    );
            } else {
                $q->where('t1.KALAB2', '>', 0)
                    ->select(
                        't1.KUNNR',
                        't1.NAME1',
                        't1.WAERK',
                        DB::raw('SUM(t1.NETPR * t1.KALAB2) AS TOTAL_VALUE'),
                        DB::raw('COUNT(DISTINCT t1.VBELN) AS SO_COUNT'),
                        DB::raw('SUM(t1.KALAB2) AS TOTAL_QTY')
                    );
            }

            $rows = $q->groupBy('t1.KUNNR', 't1.NAME1', 't1.WAERK')
                ->orderBy('t1.NAME1', 'asc')
                ->paginate(25);

            // ⬅️ hanya append ?q agar tidak bocor lagi
            if ($request->filled('q')) {
                $rows->appends(['q' => $request->query('q')]);
            }

            $grandTotalQty = ($type === 'whfg') ? $pillTotals['whfg_qty'] : $pillTotals['fg_qty'];

            $valueQuery = DB::table('so_yppr079_t1')
                ->select('WAERK', DB::raw(
                    $type === 'whfg' ? 'SUM(NETPR * KALAB) as val' : 'SUM(NETPR * KALAB2) as val'
                ))
                ->where('IV_WERKS_PARAM', $werks)
                ->when($type === 'whfg', fn($qq) => $qq->where('KALAB', '>', 0))
                ->when($type === 'fg', fn($qq) => $qq->where('KALAB2', '>', 0))
                ->groupBy('WAERK')
                ->get();

            foreach ($valueQuery as $r) $grandTotalsCurr[$r->WAERK] = (float) $r->val;
        }

        return view('stock_report', [
            'mapping'         => $mapping,
            'rows'            => $rows,
            'selected'        => ['werks' => $werks, 'type' => $type],
            'grandTotalQty'   => $grandTotalQty,
            'grandTotalsCurr' => $grandTotalsCurr,
            'pillTotals'      => $pillTotals,
        ]);
    }

    // (OPSIONAL) kalau mau redirect via POST dari JS
    public function redirector(Request $request)
    {
        $payload = $request->input('payload');
        $data = is_string($payload) ? json_decode($payload, true) : (array) $payload;
        abort_unless(is_array($data), 400, 'Invalid payload');
        $clean = array_filter($data, fn($v) => !is_null($v) && $v !== '');
        return redirect()->route('stock.index', ['q' => Crypt::encrypt($clean)]);
    }

    /**
     * API: Ambil daftar SO untuk 1 customer (level-2 table).
     */
    public function getSoByCustomer(Request $request)
    {
        $request->validate(['kunnr' => 'required', 'werks' => 'required', 'type' => 'required']);
        $type = $request->type === 'fg' ? 'fg' : 'whfg';

        $rows = DB::table('so_yppr079_t1 as t1')
            ->leftJoin('so_yppr079_t2 as t2', 't1.VBELN', '=', 't2.VBELN')
            ->where('t1.KUNNR', $request->kunnr)
            ->where('t1.IV_WERKS_PARAM', $request->werks);

        if ($type === 'whfg') {
            $rows->where('t1.KALAB', '>', 0)
                ->select(
                    't1.VBELN',
                    't2.EDATU',
                    't1.WAERK',
                    DB::raw('SUM(t1.NETPR * t1.KALAB) AS total_value'),
                    DB::raw('COUNT(t1.id) AS item_count'),
                    DB::raw('SUM(t1.KALAB) AS total_qty') // DITAMBAHKAN: Menghitung total qty WHFG per SO
                );
        } else {
            $rows->where('t1.KALAB2', '>', 0)
                ->select(
                    't1.VBELN',
                    't2.EDATU',
                    't1.WAERK',
                    DB::raw('SUM(t1.NETPR * t1.KALAB2) AS total_value'),
                    DB::raw('COUNT(t1.id) AS item_count'),
                    DB::raw('SUM(t1.KALAB2) AS total_qty') // DITAMBAHKAN: Menghitung total qty FG per SO
                );
        }

        $rows = $rows->groupBy('t1.VBELN', 't2.EDATU', 't1.WAERK')->get();

        $today = now()->startOfDay();
        foreach ($rows as $row) {
            $overdue = 0;
            $formattedEdatu = '';
            if (!empty($row->EDATU) && $row->EDATU !== '0000-00-00') {
                try {
                    $edatuDate = \Carbon\Carbon::parse($row->EDATU);
                    $formattedEdatu = $edatuDate->format('d-m-Y');
                    $overdue = $today->diffInDays($edatuDate->startOfDay(), false);
                } catch (\Exception $e) {
                }
            }
            $row->Overdue = $overdue;
            $row->FormattedEdatu = $formattedEdatu;
        }

        return response()->json(['ok' => true, 'data' => collect($rows)->sortBy('Overdue')->values()]);
    }

    /**
     * API: Ambil item untuk 1 SO (level-3 table).
     */
    public function getItemsBySo(Request $request)
    {
        $request->validate(['vbeln' => 'required', 'werks' => 'required', 'type' => 'required']);
        $type = $request->type;

        $items = DB::table('so_yppr079_t1')
            ->select(
                'id', // <-- Tambahkan ID untuk keperluan checkbox/export
                DB::raw("TRIM(LEADING '0' FROM POSNR) as POSNR"),
                'MATNR',
                'MAKTX',
                'KWMENG',
                'KALAB2', // Stock Packing
                'KALAB',  // WHFG
                'NETPR',
                'WAERK',
                'VBELN', // Tambahkan VBELN untuk keperluan itemIdToSO di FE
                DB::raw("CASE
                    WHEN '{$type}' = 'whfg' THEN (KALAB * NETPR)
                    ELSE (KALAB2 * NETPR)
                END as VALUE")
            )
            ->where('VBELN', $request->vbeln)
            ->where('IV_WERKS_PARAM', $request->werks)
            ->when($type === 'whfg', fn($q) => $q->where('KALAB', '>', 0))
            ->when($type === 'fg', fn($q) => $q->where('KALAB2', '>', 0))
            ->orderByRaw('CAST(POSNR AS UNSIGNED) asc')
            ->get();

        return response()->json(['ok' => true, 'data' => $items]);
    }

    /**
     * Export PDF / Excel untuk item terpilih (Stock).
     */
    public function exportData(Request $request)
    {
        $validated = $request->validate([
            'item_ids'    => 'required|array',
            'item_ids.*'  => 'integer',
            'export_type' => 'required|string|in:pdf,excel',
            'werks'       => 'required|string',
            'type'        => 'required|string',
        ]);

        $itemIds    = $validated['item_ids'];
        $exportType = $validated['export_type'];
        $werks      = $validated['werks'];
        $type       = $validated['type'];

        $items = DB::table('so_yppr079_t1 as t1')
            ->whereIn('t1.id', $itemIds)
            ->select(
                't1.VBELN',
                't1.KUNNR',
                DB::raw("TRIM(LEADING '0' FROM t1.POSNR) AS POSNR"),
                DB::raw("CASE WHEN t1.MATNR REGEXP '^[0-9]+$' THEN TRIM(LEADING '0' FROM t1.MATNR) ELSE t1.MATNR END AS MATNR"),
                't1.MAKTX',
                't1.KWMENG',
                't1.KALAB',
                't1.KALAB2',
                't1.NETPR',
                't1.WAERK',
                // Ambil info header tambahan (NAME1/Customer Name, PO/BSTNK)
                DB::raw("(SELECT NAME1 FROM so_yppr079_t2 WHERE VBELN = t1.VBELN LIMIT 1) AS NAME1"),
                DB::raw("(SELECT BSTNK FROM so_yppr079_t2 WHERE VBELN = t1.VBELN LIMIT 1) AS BSTNK")
            )
            ->orderBy('t1.KUNNR', 'asc')
            ->orderBy('t1.VBELN', 'asc')
            ->orderByRaw('CAST(t1.POSNR AS UNSIGNED) asc')
            ->get();

        $locationMap  = ['2000' => 'Surabaya', '3000' => 'Semarang'];
        $locationName = $locationMap[$werks] ?? $werks;
        $stockType    = $type === 'whfg' ? 'WHFG' : 'PACKING';

        $fileExtension = $exportType === 'excel' ? 'xlsx' : 'pdf';
        $fileName = "Stock_{$locationName}_{$stockType}_" . date('Ymd_His') . ".{$fileExtension}";

        if ($exportType === 'excel') {
            // 🟢 MENGAKTIFKAN EXCEL DOWNLOAD
            return Excel::download(new StockItemsExport($items, $type), $fileName);
        }

        // PDF: Pastikan data untuk header info ada di level terluar (seperti yang dilakukan di subquery)
        $dataForPdf = [
            'items'          => $items,
            'locationName'   => $locationName,
            'werks'          => $werks,
            'stockType'      => $stockType,
            'today'          => now(),
        ];

        $pdf = Pdf::loadView('sales_order.stock_pdf_template', $dataForPdf)
            ->setPaper('a4', 'landscape');

        return $pdf->stream($fileName);
    }
}
