<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Jadwal task (scheduler).
     */
    protected function schedule(Schedule $schedule): void
    {
        // NOTE: onOneServer() di-skip agar aman di local Windows/file cache
    }

    /**
     * Auto-discover semua Artisan commands di app/Console/Commands.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');
        require base_path('routes/console.php');
    }
}
