<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;
use Carbon\Carbon;

class PoReportController extends Controller
{
    // Index untuk menampilkan halaman PO Report (mode tabel)
    public function index(Request $request)
    {
        $decryptedParams = [];
        if ($request->has('q')) {
            try {
                $decryptedParams = Crypt::decrypt($request->query('q'));
                if (!is_array($decryptedParams)) {
                    $decryptedParams = [];
                }
            } catch (DecryptException $e) {
                return redirect()->route('dashboard')->withErrors('Link Report tidak valid.');
            }
        }

        // Merge hasil dekripsi ke $request
        if (!empty($decryptedParams)) {
            $request->merge($decryptedParams);
        }

        $werks = $request->query('werks');
        $auart = $request->query('auart');
        $compact = $request->boolean('compact', true); // Selalu true di halaman report
        $show = filled($werks) && filled($auart);
        $rows = collect();

        // Ambil data mapping untuk navigation pills di header
        $mapping = DB::table('maping')
            ->select('IV_WERKS', 'IV_AUART', 'Deskription')
            ->orderBy('IV_WERKS')->orderBy('IV_AUART')
            ->get()
            ->groupBy('IV_WERKS')
            ->map(fn($g) => $g->unique('IV_AUART')->values());

        $selectedDescription = '';
        $selectedMapping = $mapping[$werks]->firstWhere('IV_AUART', $auart) ?? null;
        if ($selectedMapping) {
            $selectedDescription = $selectedMapping->Deskription ?? '';
        }
        if ($request->filled('werks') && !$request->filled('auart') && !$request->has('q')) {
            $typesForPlant = collect($mapping[$werks] ?? []);

            // Helper cocokkan deskripsi AUART (case-insensitive)
            $like = fn($str) => fn($row) => str_contains(strtolower((string)($row->Deskription ?? '')), $str);

            // 1) Prioritas: mengandung "kmi" dan "export"
            $default = $typesForPlant->first(function ($row) {
                $d = strtolower((string)($row->Deskription ?? ''));
                return str_contains($d, 'kmi') && str_contains($d, 'export')
                    && !str_contains($d, 'replace') && !str_contains($d, 'local');
            });

            // 2) Jika tidak ada, pilih yang "Export" (bukan Replace/Local)
            if (!$default) {
                $default = $typesForPlant->first(function ($row) {
                    $d = strtolower((string)($row->Deskription ?? ''));
                    return str_contains($d, 'export')
                        && !str_contains($d, 'replace') && !str_contains($d, 'local');
                });
            }

            // 3) Jika masih tidak ada, ambil item pertama (fallback terakhir)
            if (!$default) {
                $default = $typesForPlant->first();
            }

            if ($default) {
                // Redirect ke route report dengan payload terenkripsi
                $payload = [
                    'werks'   => $werks,
                    'auart'   => $default->IV_AUART,
                    'compact' => 1, // tetap mode tabel kompak
                ];
                return redirect()->route('po.report', ['q' => Crypt::encrypt($payload)]);
            }
        }

        // ====== Logika Report (Mode Tabel) - CUMA JALAN JIKA $show=true ======
        if ($show) {
            // Parser tanggal yang sama persis seperti di DashboardController lama
            $safeEdatu = "COALESCE(
                STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
                STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
            )";
            $safeEdatuInner = "COALESCE(
                STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2_inner.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
                STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2_inner.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
            )";

            // Query yang sama persis dengan yang ada di DashboardController
            $query = DB::table('so_yppr079_t2 as t2')
                ->leftJoin(DB::raw('(
                    SELECT t2_inner.KUNNR, SUM(t1.TOTPR) AS TOTAL_TOTPR
                    FROM so_yppr079_t2 AS t2_inner
                    LEFT JOIN so_yppr079_t1 AS t1
                      ON TRIM(CAST(t1.VBELN AS CHAR)) = TRIM(CAST(t2_inner.VBELN AS CHAR))
                    WHERE t2_inner.IV_WERKS_PARAM = ' . DB::getPdo()->quote($werks) . '
                      AND t2_inner.IV_AUART_PARAM  = ' . DB::getPdo()->quote($auart) . '
                      AND ' . $safeEdatuInner . ' < CURDATE()
                    GROUP BY t2_inner.KUNNR
                ) AS totpr_sum'), 't2.KUNNR', '=', 'totpr_sum.KUNNR')
                ->select(
                    't2.KUNNR',
                    DB::raw('MAX(t2.NAME1) AS NAME1'),
                    DB::raw('COALESCE(MAX(totpr_sum.TOTAL_TOTPR), 0) AS TOTPR'),
                    DB::raw('MAX(t2.WAERK) AS WAERK'),
                    DB::raw('COUNT(DISTINCT TRIM(CAST(t2.VBELN AS CHAR))) AS SO_COUNT'),
                    DB::raw("SUM(CASE WHEN {$safeEdatu} < CURDATE() THEN 1 ELSE 0 END) AS SO_LATE_COUNT"),
                    DB::raw("ROUND((SUM(CASE WHEN {$safeEdatu} < CURDATE() THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT TRIM(CAST(t2.VBELN AS CHAR))), 0)) * 100, 2) AS LATE_PCT")
                )
                ->where('t2.IV_WERKS_PARAM', $werks)
                ->where('t2.IV_AUART_PARAM', $auart)
                ->groupBy('t2.KUNNR')
                ->orderBy('NAME1');

            $rows = $query->paginate(25)->withQueryString();
        }

        return view('po_report.po_report', [ // <<< GANTI POINTER VIEW DI SINI
            'mapping' => $mapping,
            'selected' => ['werks' => $werks, 'auart' => $auart],
            'selectedDescription' => $selectedDescription,
            'rows' => $rows,
            'compact' => $compact,
            'show' => $show,
            // Jika Anda ingin mempertahankan highlight dari search
            'highlight_kunnr' => $request->query('highlight_kunnr'),
            'highlight_vbeln' => $request->query('highlight_vbeln'),
        ]);
    }

    // CATATAN: Karena fungsi apiT2 dan apiT3 adalah generic logic untuk menampilkan nested data (L2 dan L3), 
    // kita biarkan mereka di DashboardController dan hanya mengubah route name.
}
