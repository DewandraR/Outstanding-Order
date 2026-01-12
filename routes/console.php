<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Cache;

const YPPR_SYNC_LOCK = 'lock:yppr_sync_running';

// ✅ PRIORITAS 1: YPPR COMP (jalan 1x sehari jam 23:00, background biar bisa barengan)
Schedule::command('yppr:sync-comp')
    ->cron('0 23 * * *')
    ->timezone('Asia/Jakarta')
    ->before(function () {
        echo now()->format('Y-m-d H:i:s') . ' Running ["artisan" yppr:sync-comp]' . PHP_EOL;
    })
    ->sendOutputTo(storage_path('logs/yppr_sync_comp.log'))
    ->runInBackground()
    ->withoutOverlapping();


// ✅ PRIORITAS 2: YPPR SYNC (tiap jam, pasang lock supaya STOCK nunggu)
Schedule::command('yppr:sync')
    ->cron('0 */1 * * *')
    ->timezone('Asia/Jakarta')
    ->before(function () {
        // TTL dibuat lebih lama dari kemungkinan durasi sync (misal 9 jam) supaya aman kalau ada crash
        Cache::put(YPPR_SYNC_LOCK, true, now()->addHours(9));

        echo now()->format('Y-m-d H:i:s') . ' Running ["artisan" yppr:sync]' . PHP_EOL;
    })
    ->after(function () {
        Cache::forget(YPPR_SYNC_LOCK);
    })
    ->sendOutputTo(storage_path('logs/yppr_sync.log'))
    ->withoutOverlapping();


// ✅ STOCK: harus nunggu YPPR SYNC selesai (kalau lock masih ada, stock diskip dulu)
Schedule::command('stock:sync')
    ->cron('0 */1 * * *')
    ->timezone('Asia/Jakarta')
    ->when(function () {
        return ! Cache::has(YPPR_SYNC_LOCK);
    })
    ->before(function () {
        echo now()->format('Y-m-d H:i:s') . ' Running ["artisan" stock:sync]' . PHP_EOL;
    })
    ->sendOutputTo(storage_path('logs/stock_sync.log'))
    ->withoutOverlapping();


Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
