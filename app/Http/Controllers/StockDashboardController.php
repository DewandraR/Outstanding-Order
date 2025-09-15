<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockDashboardController extends Controller
{
    public function index(Request $request)
    {
        $location = $request->query('location'); // '2000' | '3000' | null

        // ===== KPI =====
        $baseWhfg = DB::table('so_yppr079_t1 as s')
            ->where('s.KALAB', '>', 0)
            ->when($location, fn($q, $loc) => $q->where('s.IV_WERKS_PARAM', $loc));

        $baseFg = DB::table('so_yppr079_t1 as s')
            ->where('s.KALAB2', '>', 0)
            ->when($location, fn($q, $loc) => $q->where('s.IV_WERKS_PARAM', $loc));

        $kpi = [
            'whfg_total_value_usd' => (clone $baseWhfg)->where('s.WAERK', 'USD')
                ->selectRaw('COALESCE(SUM(s.NETPR * s.KALAB),0) AS v')->value('v'),
            'whfg_count'           => (clone $baseWhfg)->count(),
            'fg_total_value_usd'   => (clone $baseFg)->where('s.WAERK', 'USD')
                ->selectRaw('COALESCE(SUM(s.NETPR * s.KALAB2),0) AS v')->value('v'),
            'fg_count'             => (clone $baseFg)->count(),
        ];

        // ===== Qty per customer (sekali query) =====
        $perCustomer = DB::table('so_yppr079_t1 as s')
            ->when($location, fn($q, $loc) => $q->where('s.IV_WERKS_PARAM', $loc))
            ->whereNotNull('s.NAME1')->where('s.NAME1', '!=', '')
            ->select(
                's.NAME1',
                DB::raw('SUM(CASE WHEN s.KALAB  > 0 THEN s.KALAB  ELSE 0 END) AS whfg_qty'),
                DB::raw('SUM(CASE WHEN s.KALAB2 > 0 THEN s.KALAB2 ELSE 0 END) AS fg_qty')
            )
            ->groupBy('s.NAME1')
            ->get();

        // Ranking ALL (tanpa nol)
        $rankWhfg = $perCustomer->filter(fn($r) => (float)$r->whfg_qty > 0)
                                ->sortByDesc('whfg_qty')->values();
        $rankFg   = $perCustomer->filter(fn($r) => (float)$r->fg_qty > 0)
                                ->sortByDesc('fg_qty')->values();

        // Mapping untuk sidebar
        $mapping = DB::table('maping')
            ->select('IV_WERKS', 'IV_AUART', 'Deskription')
            ->orderBy('IV_WERKS')->orderBy('IV_AUART')
            ->get()
            ->groupBy('IV_WERKS')
            ->map(fn($g) => $g->unique('IV_AUART')->values());

        $dashboardData = [
            'kpi'          => $kpi,
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
