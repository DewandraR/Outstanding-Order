<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// --- Jadwal untuk YPPR079 (Sudah Ada & Synchronous) ---
Schedule::command('yppr:sync')
    ->dailyAt('04:00')
    ->timezone('Asia/Jakarta')
    ->before(function () {
        echo now()->format('Y-m-d H:i:s') . ' Running ["artisan" yppr:sync]' . PHP_EOL;
    })
    ->sendOutputTo(storage_path('logs/yppr_sync.log'));


// 🌟 --- Jadwal YSDR048 (Diubah menjadi Synchronous) ---
Schedule::command('stock:sync')
    ->dailyAt('04:00')
    ->timezone('Asia/Jakarta')
    ->before(function () {
        echo now()->format('Y-m-d H:i:s') . ' Running ["artisan" stock:sync]' . PHP_EOL;
    })
    ->sendOutputTo(storage_path('logs/stock_sync.log'))
    ->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
