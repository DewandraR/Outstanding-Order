<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class MapingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Hapus data lama untuk menghindari duplikasi saat seeder dijalankan ulang
        DB::table('maping')->truncate();

        // Masukkan data mapping yang dibutuhkan oleh aplikasi dan skrip Python
        DB::table('maping')->insert([
            // Data untuk Surabaya
            ['IV_WERKS' => '2000', 'IV_AUART' => 'ZOR1', 'Deskription' => 'KMI Export SBY'],
            ['IV_WERKS' => '2000', 'IV_AUART' => 'ZOR3', 'Deskription' => 'KMI Local SBY'],
            ['IV_WERKS' => '2000', 'IV_AUART' => 'ZRP1', 'Deskription' => 'KMI Replace SBY'],

            // Data untuk Semarang
            ['IV_WERKS' => '3000', 'IV_AUART' => 'ZOR2', 'Deskription' => 'KMI Export SMG'],
            ['IV_WERKS' => '3000', 'IV_AUART' => 'ZOR4 ', 'Deskription' => 'KMI Local SMG'],
            ['IV_WERKS' => '3000', 'IV_AUART' => 'ZRP2', 'Deskription' => 'KMI Replace SMG'],
        ]);
    }
}
