<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process as SymfonyProcess;

class SyncYsdr048 extends Command
{
    /**
     * Nama dan signature dari console command.
     * Menggunakan flag --sync_stock sesuai dengan api.py yang baru.
     *
     * @var string
     */
    protected $signature = 'stock:sync';

    /**
     * Deskripsi console command.
     *
     * @var string
     */
    protected $description = 'Sync Z_FM_YSDR048 Stock data via api.py --sync_stock';

    /**
     * Jalankan console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $apiPath = base_path('api.py');
        if (!file_exists($apiPath)) {
            $this->error("api.py tidak ditemukan di: {$apiPath}");
            return self::FAILURE;
        }

        $python = $this->detectPython();
        $this->info("Python: {$python}");

        // 🌟 Perintah: Menggunakan flag --sync_stock dan timeout yang besar (2 jam = 7200 detik)
        $cmd = [$python, $apiPath, '--sync_stock', '--timeout', '7200'];

        // Timeout PHP diatur ke 2 jam (7200 detik)
        $process = new SymfonyProcess($cmd, base_path(), null, null, 7200.0);
        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        if (!$process->isSuccessful()) {
            $this->error('Stock Sync failed');
            // Menampilkan output error untuk debugging
            $this->error($process->getErrorOutput());
            return self::FAILURE;
        }

        $this->info('Stock Sync done');
        return self::SUCCESS;
    }

    /**
     * Mendeteksi path biner Python yang valid (disalin dari SyncYppr079).
     *
     * @return string
     */
    private function detectPython(): string
    {
        $candidates = [
            base_path('venv\\Scripts\\python.exe'),
            base_path('venv\\Scripts\\python'),
            base_path('venv/bin/python'),
            'py',
            'python',
            'python3',
        ];
        foreach ($candidates as $bin) {
            if (preg_match('/[\\\\\\/]/', $bin)) {
                if (file_exists($bin)) return $bin;
            } else {
                try {
                    $p = new SymfonyProcess([$bin, '--version']);
                    $p->setTimeout(5);
                    $p->run();
                    if ($p->isSuccessful()) return $bin;
                } catch (\Throwable $e) {
                }
            }
        }
        return 'python';
    }
}
