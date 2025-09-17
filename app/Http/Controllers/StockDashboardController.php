<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockDashboardController extends Controller
{
    public function index(Request $request)
    {
        $location = $request->query('location'); // '2000' | '3000' | null

        // ===== BASE QUERY =====
        $baseWhfg = DB::table('so_yppr079_t1 as s')
            ->where('s.KALAB', '>', 0)
            ->when($location, fn($q, $loc) => $q->where('s.IV_WERKS_PARAM', $loc));

        $baseFg = DB::table('so_yppr079_t1 as s')
            ->where('s.KALAB2', '>', 0)
            ->when($location, fn($q, $loc) => $q->where('s.IV_WERKS_PARAM', $loc));

        // ===== KPI (sesuai Stock Report) =====
        $kpi = [
            // Total VALUE (USD)
            'whfg_total_value_usd' => (clone $baseWhfg)->where('s.WAERK', 'USD')
                ->selectRaw('COALESCE(SUM(s.NETPR * s.KALAB),0) AS v')->value('v'),
            'fg_total_value_usd'   => (clone $baseFg)->where('s.WAERK', 'USD')
                ->selectRaw('COALESCE(SUM(s.NETPR * s.KALAB2),0) AS v')->value('v'),

            // Total QTY (ambil dari Stock Report: Σ KALAB / Σ KALAB2)
            'whfg_qty'             => (clone $baseWhfg)
                ->selectRaw('COALESCE(SUM(s.KALAB),0) AS q')->value('q'),
            'fg_qty'               => (clone $baseFg)
                ->selectRaw('COALESCE(SUM(s.KALAB2),0) AS q')->value('q'),
        ];

        // ===== Top customers + breakdown lokasi =====
        $perCustomerPerLocation = DB::table('so_yppr079_t1 as s')
            ->when($location, fn($q, $loc) => $q->where('s.IV_WERKS_PARAM', $loc))
            ->whereNotNull('s.NAME1')->where('s.NAME1', '!=', '')
            ->select(
                's.NAME1',
                's.IV_WERKS_PARAM',
                DB::raw('SUM(CASE WHEN s.KALAB  > 0 THEN s.KALAB  ELSE 0 END) AS whfg_qty'),
                DB::raw('SUM(CASE WHEN s.KALAB2 > 0 THEN s.KALAB2 ELSE 0 END) AS fg_qty')
            )
            ->groupBy('s.NAME1', 's.IV_WERKS_PARAM')
            ->get();

        $customerData = collect($perCustomerPerLocation)
            ->groupBy('NAME1')
            ->map(function ($items, $name) {
                $whfg_breakdown = $items->pluck('whfg_qty', 'IV_WERKS_PARAM');
                $fg_breakdown   = $items->pluck('fg_qty', 'IV_WERKS_PARAM');

                return [
                    'NAME1'     => $name,
                    'whfg_qty'  => $items->sum('whfg_qty'),
                    'fg_qty'    => $items->sum('fg_qty'),
                    'breakdown' => [
                        'whfg' => $whfg_breakdown,
                        'fg'   => $fg_breakdown,
                    ],
                ];
            })->values();

        $rankWhfg = $customerData->filter(fn($r) => (float)$r['whfg_qty'] > 0)->sortByDesc('whfg_qty')->values();
        $rankFg   = $customerData->filter(fn($r) => (float)$r['fg_qty'] > 0)->sortByDesc('fg_qty')->values();

        // Sidebar mapping (tidak berubah)
        $mapping = DB::table('maping')
            ->select('IV_WERKS', 'IV_AUART', 'Deskription')
            ->orderBy('IV_WERKS')->orderBy('IV_AUART')
            ->get()
            ->groupBy('IV_WERKS')
            ->map(fn($g) => $g->unique('IV_AUART')->values());

        $dashboardData = [
            'kpi' => $kpi,
            'topCustomers' => [
                'whfg' => $rankWhfg,
                'fg'   => $rankFg,
            ],
        ];

        return view('stock_dashboard.dashboard', [
            'dashboardData'    => $dashboardData,
            'selectedLocation' => $location,
            'mapping'          => $mapping,
        ]);
    }
}
