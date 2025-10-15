<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;

class StockIssueController extends Controller
{
    /**
     * Menampilkan daftar Stock Issue berdasarkan level (assy, ptg, pkg).
     */
    public function index(Request $request)
    {
        $payload = [];
        $level = null;
        $werks = null;
        $title = 'Stock Issue Report';
        $stockData = collect();
        $table = null;
        $paramValue = null; // Nilai IV_PARAM yang akan digunakan sebagai filter

        // 1. Dekripsi Payload dari Query Parameter 'q'
        if ($request->filled('q')) {
            try {
                // Crypt::decrypt() kompatibel dengan Crypt::encrypt(array) dari Blade
                $payload = Crypt::decrypt($request->query('q'));
                if (is_array($payload)) {
                    $werks = $payload['werks'] ?? null; // Lokasi (mis. '3000')
                    $level = $payload['level'] ?? null; // Level (mis. 'assy')
                } else {
                    // Jika hasil decrypt bukan array
                    abort(404, 'Struktur tautan laporan tidak valid.');
                }
            } catch (DecryptException $e) {
                // Jika dekripsi gagal
                abort(404, 'Tautan laporan tidak valid atau kadaluarsa.');
            }

            // 2. Tentukan Tabel, Judul, dan NILAI FILTER IV_PARAM Berdasarkan Level
            switch (strtolower($level)) {
                case 'assy':
                    $table = 'stock_assy';
                    $title = 'Stock Issue - Level ASSY';
                    $paramValue = 'IV_SIAD'; // Nilai IV_PARAM untuk ASSY
                    break;
                case 'ptg':
                    $table = 'stock_ptg';
                    $title = 'Stock Issue - Level PTG';
                    $paramValue = 'IV_SIPD'; // Nilai IV_PARAM untuk PTG
                    break;
                case 'pkg':
                    $table = 'stock_pkg';
                    $title = 'Stock Issue - Level PKG';
                    $paramValue = 'IV_SIPP'; // Nilai IV_PARAM untuk PKG
                    break;
                default:
                    // Jika level tidak valid tetapi URL terenkripsi ada
                    abort(404, 'Level Stock Issue tidak dikenali atau tidak valid.');
            }

            // 3. Ambil Data dari Database jika tabel dan nilai param ditemukan
            if ($table && $paramValue) {
                // Tambahkan filter lokasi jika diperlukan, saat ini hanya fokus pada filter level
                // asumsikan tabel stock_assy/ptg/pkg sudah spesifik untuk lokasi werks yang relevan (misal: 3000)
                $stockData = DB::table($table . ' as s')
                    // Filter berdasarkan parameter level
                    ->where('s.IV_PARAM', $paramValue)
                    // Hanya tampilkan stok yang lebih dari 0
                    ->where('s.STOCK3', '>', 0)
                    ->select([
                        's.NAME1',      // Costumer
                        's.VBELN',      // Sales Order
                        's.POSNR',      // Item
                        's.MATNH',      // Material Finish
                        's.MAKTXH',     // Desc
                        's.STOCK3',     // Stock On Hand
                        's.MEINS',      // Uom
                        DB::raw('COALESCE(s.STOCK3 * s.NETPR, 0) AS TPRC'), // Total Value
                    ])
                    // Urutkan untuk keperluan row merging di Blade
                    ->orderBy('s.NAME1')
                    ->orderBy('s.VBELN')
                    ->orderBy('s.POSNR')
                    ->get();
            }
        } else {
            // Kasus akses tanpa parameter 'q' (mungkin di-redirect dari halaman lain atau akses default)
            // Biarkan $level dan $werks menjadi null, dan $stockData kosong
        }

        // 4. Kirim Data ke View
        return view('stock_issue.stock_issue_report', [
            'stockData' => $stockData,
            'title'     => $title,
            'level'     => $level, // Penting untuk penanda 'active' di Nav Pills
            'werks'     => $werks, // Penting untuk pembuatan link Nav Pills
        ]);
    }
}
