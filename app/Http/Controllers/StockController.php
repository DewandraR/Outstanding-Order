<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class StockController extends Controller
{
    /**
     * Menampilkan halaman utama Laporan Stok.
     */
    public function index(Request $request)
    {
        $mapping = DB::table('maping')
            ->select('IV_WERKS', 'IV_AUART', 'Deskription')
            ->orderBy('IV_WERKS')->orderBy('IV_AUART')
            ->get()
            ->groupBy('IV_WERKS')
            ->map(fn($g) => $g->unique('IV_AUART')->values());

        $werks = $request->query('werks');

        // kalau user belum pilih type, tetap redirect ke default agar tabelnya jelas
        if ($werks && !$request->has('type')) {
            return redirect()->route('stock.index', ['werks' => $werks, 'type' => 'whfg']);
        }

        $type = $request->query('type') === 'fg' ? 'fg' : 'whfg';

        // ============== NEW: total qty utk pill, selalu dihitung ==============
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
        // =====================================================================

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
                ->paginate(25)
                ->withQueryString();

            // grand total qty (untuk footer & pill aktif)
            $grandTotalQty = ($type === 'whfg') ? $pillTotals['whfg_qty'] : $pillTotals['fg_qty'];

            // grand total value per currency
            $valueQuery = DB::table('so_yppr079_t1')
                ->select('WAERK', DB::raw(
                    $type === 'whfg'
                        ? 'SUM(NETPR * KALAB) as val'
                        : 'SUM(NETPR * KALAB2) as val'
                ))
                ->where('IV_WERKS_PARAM', $werks)
                ->when($type === 'whfg', fn($qq) => $qq->where('KALAB', '>', 0))
                ->when($type === 'fg',   fn($qq) => $qq->where('KALAB2', '>', 0))
                ->groupBy('WAERK')
                ->get();

            foreach ($valueQuery as $r) {
                $grandTotalsCurr[$r->WAERK] = (float) $r->val;
            }
        }

        return view('stock_report', [
            'mapping'        => $mapping,
            'rows'           => $rows,
            'selected'       => ['werks' => $werks, 'type' => $type],
            'grandTotalQty'  => $grandTotalQty,
            'grandTotalsCurr' => $grandTotalsCurr,
            // NEW: kirim total qty utk kedua pill
            'pillTotals'     => $pillTotals,
        ]);
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
                DB::raw("TRIM(LEADING '0' FROM POSNR) as POSNR"),
                'MATNR',
                'MAKTX',
                'KWMENG',
                'KALAB2', // Stock Packing
                'KALAB',  // WHFG
                'NETPR',
                'WAERK',
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
}
