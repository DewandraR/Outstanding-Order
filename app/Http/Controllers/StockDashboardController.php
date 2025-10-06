<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;                 // ⬅️ tambah
use Illuminate\Contracts\Encryption\DecryptException; // ⬅️ tambah

class StockDashboardController extends Controller
{
    public function index(Request $request)
    {
        // 1) ambil lokasi dari q (terenkripsi)
        $location = null;
        if ($request->filled('q')) {
            try {
                $payload = Crypt::decrypt($request->query('q'));
                if (is_array($payload)) {
                    $location = $payload['location'] ?? null; // '2000' | '3000' | null
                }
            } catch (DecryptException $e) {
                abort(404);
            }
        }
        // KODE ELSEIF INI DIHAPUS

        // ===== BASE QUERY =====
        $baseWhfg = DB::table('so_yppr079_t1 as s')
            ->where('s.KALAB', '>', 0)
            ->when($location, fn($q, $loc) => $q->where('s.IV_WERKS_PARAM', $loc));

        $baseFg = DB::table('so_yppr079_t1 as s')
            ->where('s.KALAB2', '>', 0)
            ->when($location, fn($q, $loc) => $q->where('s.IV_WERKS_PARAM', $loc));

        // ===== KPI (tetap) =====
        $kpi = [
            'whfg_total_value_usd' => (clone $baseWhfg)->where('s.WAERK', 'USD')->selectRaw('COALESCE(SUM(s.NETPR*s.KALAB),0) v')->value('v'),
            'fg_total_value_usd'   => (clone $baseFg)->where('s.WAERK', 'USD')->selectRaw('COALESCE(SUM(s.NETPR*s.KALAB2),0) v')->value('v'),
            'whfg_qty'             => (clone $baseWhfg)->selectRaw('COALESCE(SUM(s.KALAB),0) q')->value('q'),
            'fg_qty'               => (clone $baseFg)->selectRaw('COALESCE(SUM(s.KALAB2),0) q')->value('q'),
        ];

        // ===== Top customers + breakdown lokasi (tetap) =====
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
                return [
                    'NAME1'     => $name,
                    'whfg_qty'  => $items->sum('whfg_qty'),
                    'fg_qty'    => $items->sum('fg_qty'),
                    'breakdown' => [
                        'whfg' => $items->pluck('whfg_qty', 'IV_WERKS_PARAM'),
                        'fg'   => $items->pluck('fg_qty', 'IV_WERKS_PARAM'),
                    ],
                ];
            })->values();

        $rankWhfg = $customerData->where('whfg_qty', '>', 0)->sortByDesc('whfg_qty')->values();
        $rankFg   = $customerData->where('fg_qty', '>', 0)->sortByDesc('fg_qty')->values();

        $mapping = DB::table('maping')
            ->select('IV_WERKS', 'IV_AUART', 'Deskription')
            ->orderBy('IV_WERKS')->orderBy('IV_AUART')
            ->get()
            ->groupBy('IV_WERKS')
            ->map(fn($g) => $g->unique('IV_AUART')->values());

        $dashboardData = [
            'kpi' => $kpi,
            'topCustomers' => ['whfg' => $rankWhfg, 'fg' => $rankFg],
        ];

        return view('stock_dashboard.dashboard', [
            'dashboardData'    => $dashboardData,
            'selectedLocation' => $location,   // <- pakai ini di Blade untuk status 'active'
            'mapping'          => $mapping,
        ]);
    }
}
