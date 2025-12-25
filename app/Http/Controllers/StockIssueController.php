<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Exports\StockIssueExport;
use Maatwebsite\Excel\Facades\Excel;


class StockIssueController extends Controller
{
    /**
     * Menampilkan daftar Stock Issue berdasarkan level (assy, ptg, pkg).
     */
    public function index(Request $request)
    {
        $payload = [];
        $level = null;
        $sub_level = null; // <-- BARU: Untuk Wood/Metal di PTG
        $werks = null;
        $title = 'Stock Issue Report';
        $stockData = collect();
        $table = null;
        $paramValue = null;

        // 1. Dekripsi Payload dari Query Parameter 'q'
        if ($request->filled('q')) {
            try {
                $payload = Crypt::decrypt($request->query('q'));
                if (is_array($payload)) {
                    $werks = $payload['werks'] ?? null;
                    $level = $payload['level'] ?? null;
                    $sub_level = $payload['sub_level'] ?? null; // <-- BARU
                } else {
                    abort(404, 'Struktur tautan laporan tidak valid.');
                }
            } catch (DecryptException $e) {
                abort(404, 'Tautan laporan tidak valid atau kadaluarsa.');
            }

            // 2. Tentukan Tabel, Judul, dan NILAI FILTER IV_PARAM Berdasarkan Level
            switch (strtolower($level)) {
                case 'assy':
                    $table = 'stock_assy';
                    $title = 'Stock Issue - Level ASSY';
                    $paramValue = 'IV_SIAD';
                    break;

                case 'ptg':
                    // LOGIKA BARU UNTUK SUB-LEVEL PTG (WOOD/METAL)
                    $title = 'Stock Issue - Level PTG';
                    $sub_level = strtolower($sub_level) == 'metal' ? 'metal' : 'wood'; // Default: wood

                    if ($sub_level == 'metal') {
                        // IV_SIPM dari tabel stock_ptg_m
                        $table = 'stock_ptg_m';
                        $paramValue = 'IV_SIPM';
                        $title .= ' (Metal)';
                    } else {
                        // IV_SIPD dari tabel stock_ptg
                        $table = 'stock_ptg';
                        $paramValue = 'IV_SIPD';
                        $title .= ' (Wood)';
                    }
                    break;

                case 'pkg':
                    $table = 'stock_pkg';
                    $title = 'Stock Issue - Level PKG';
                    $paramValue = 'IV_SIPP';
                    break;

                default:
                    abort(404, 'Level Stock Issue tidak dikenali atau tidak valid.');
            }

            // 3. Ambil Data dari Database jika tabel dan nilai param ditemukan
            if ($table && $paramValue) {
                $stockData = DB::table($table . ' as s')
                    ->where('s.IV_PARAM', $paramValue)
                    ->where('s.STOCK3', '>', 0)
                    ->select([
                        's.NAME1',
                        // 🚨 PERUBAHAN UTAMA DI SINI: Menggunakan DB::raw untuk TRIM LEADING ZEROS
                        DB::raw("TRIM(LEADING '0' FROM s.VBELN) AS VBELN"), // Tambahan: Sales Order (VBELN)
                        DB::raw("TRIM(LEADING '0' FROM s.POSNR) AS POSNR"), // Item
                        DB::raw("TRIM(LEADING '0' FROM s.MATNH) AS MATNH"), // Material Finish
                        's.MAKTXH',
                        's.STOCK3',
                        's.MEINS',
                        DB::raw('COALESCE(s.STOCK3 * s.NETPR, 0) AS TPRC'),
                    ])
                    ->orderBy('s.NAME1')
                    ->orderBy('s.VBELN')
                    ->orderBy('s.POSNR')
                    ->get();
            }
        } else {
            // Kasus akses tanpa parameter 'q'
        }

        // 4. Kirim Data ke View
        return view('stock_issue.stock_issue_report', [
            'stockData' => $stockData,
            'title'     => $title,
            'level'     => $level,
            'sub_level' => $sub_level, // <-- BARU: Dikirim ke Blade
            'werks'     => $werks,
        ]);
    }
    public function exportPdf(Request $request)
    {
        $payload = [];
        $level = null;
        $sub_level = null;
        $werks = null;

        $title = 'Stock Issue Report';
        $stockData = collect();
        $table = null;
        $paramValue = null;

        if (!$request->filled('q')) {
            abort(404, 'Parameter export tidak valid.');
        }

        try {
            $payload = Crypt::decrypt($request->query('q'));
            if (!is_array($payload)) abort(404, 'Struktur tautan laporan tidak valid.');

            $werks = $payload['werks'] ?? null;
            $level = $payload['level'] ?? null;
            $sub_level = $payload['sub_level'] ?? null;
        } catch (DecryptException $e) {
            abort(404, 'Tautan laporan tidak valid atau kadaluarsa.');
        }

        // Tentukan tabel & param berdasarkan level/sublevel (SAMA persis seperti index)
        switch (strtolower($level ?? '')) {
            case 'assy':
                $table = 'stock_assy';
                $title = 'Stock Issue - Level ASSY';
                $paramValue = 'IV_SIAD';
                break;

            case 'ptg':
                $title = 'Stock Issue - Level PTG';
                $sub_level = strtolower($sub_level) == 'metal' ? 'metal' : 'wood'; // default wood

                if ($sub_level == 'metal') {
                    $table = 'stock_ptg_m';
                    $paramValue = 'IV_SIPM';
                    $title .= ' (Metal)';
                } else {
                    $table = 'stock_ptg';
                    $paramValue = 'IV_SIPD';
                    $title .= ' (Wood)';
                }
                break;

            case 'pkg':
                $table = 'stock_pkg';
                $title = 'Stock Issue - Level PKG';
                $paramValue = 'IV_SIPP';
                break;

            default:
                abort(404, 'Level Stock Issue tidak dikenali atau tidak valid.');
        }

        // Ambil data (kolom yang dibutuhkan PDF)
        $stockData = DB::table($table . ' as s')
            ->where('s.IV_PARAM', $paramValue)
            ->where('s.STOCK3', '>', 0)
            ->select([
                's.NAME1',
                DB::raw("TRIM(LEADING '0' FROM s.VBELN) AS VBELN"),
                DB::raw("TRIM(LEADING '0' FROM s.POSNR) AS POSNR"),
                DB::raw("TRIM(LEADING '0' FROM s.MATNH) AS MATNH"),
                's.MAKTXH',
                's.STOCK3',
                's.MEINS',
                DB::raw('COALESCE(s.STOCK3 * s.NETPR, 0) AS TPRC'),
            ])
            ->orderBy('s.NAME1')
            ->orderBy('s.VBELN')
            ->orderBy('s.POSNR')
            ->get();

        // Render PDF
        $pdf = Pdf::loadView('stock_issue.stock_issue_report_pdf', [
            'stockData' => $stockData,
            'title'     => $title,
            'level'     => $level,
            'sub_level' => $sub_level,
            'werks'     => $werks,
        ])->setPaper('A4', 'portrait');

        $filename = 'stock_issue_' . str_replace([' ', '(', ')'], ['_', '', ''], strtolower($title)) . '_' . now()->format('Ymd_His') . '.pdf';

        return $pdf->download($filename);
    }
    public function exportExcel(Request $request)
    {
        $payload = [];
        $level = null;
        $sub_level = null;
        $werks = null;

        $title = 'Stock Issue Report';
        $stockData = collect();
        $table = null;
        $paramValue = null;

        if (!$request->filled('q')) abort(404, 'Parameter export tidak valid.');

        try {
            $payload = Crypt::decrypt($request->query('q'));
            if (!is_array($payload)) abort(404, 'Struktur tautan laporan tidak valid.');

            $werks = $payload['werks'] ?? null;
            $level = $payload['level'] ?? null;
            $sub_level = $payload['sub_level'] ?? null;
        } catch (DecryptException $e) {
            abort(404, 'Tautan laporan tidak valid atau kadaluarsa.');
        }

        switch (strtolower($level ?? '')) {
            case 'assy':
                $table = 'stock_assy';
                $title = 'Stock Issue - Level ASSY';
                $paramValue = 'IV_SIAD';
                break;

            case 'ptg':
                $title = 'Stock Issue - Level PTG';
                $sub_level = strtolower($sub_level) == 'metal' ? 'metal' : 'wood';

                if ($sub_level == 'metal') {
                    $table = 'stock_ptg_m';
                    $paramValue = 'IV_SIPM';
                    $title .= ' (Metal)';
                } else {
                    $table = 'stock_ptg';
                    $paramValue = 'IV_SIPD';
                    $title .= ' (Wood)';
                }
                break;

            case 'pkg':
                $table = 'stock_pkg';
                $title = 'Stock Issue - Level PKG';
                $paramValue = 'IV_SIPP';
                break;

            default:
                abort(404, 'Level Stock Issue tidak dikenali atau tidak valid.');
        }

        $stockData = DB::table($table . ' as s')
            ->where('s.IV_PARAM', $paramValue)
            ->where('s.STOCK3', '>', 0)
            ->select([
                's.NAME1',
                DB::raw("TRIM(LEADING '0' FROM s.VBELN) AS VBELN"),
                DB::raw("TRIM(LEADING '0' FROM s.POSNR) AS POSNR"),
                DB::raw("TRIM(LEADING '0' FROM s.MATNH) AS MATNH"),
                's.MAKTXH',
                's.STOCK3',
                's.MEINS',
                DB::raw('COALESCE(s.STOCK3 * s.NETPR, 0) AS TPRC'),
            ])
            ->orderBy('s.NAME1')
            ->orderBy('s.VBELN')
            ->orderBy('s.POSNR')
            ->get();

        $filename = 'stock_issue_' . str_replace([' ', '(', ')'], ['_', '', ''], strtolower($title))
            . '_' . now()->format('Ymd_His') . '.xlsx';

        return Excel::download(new StockIssueExport(collect($stockData)), $filename);
    }

}
