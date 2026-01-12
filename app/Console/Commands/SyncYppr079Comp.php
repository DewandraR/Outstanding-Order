<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process as SymfonyProcess;

class SyncYppr079Comp extends Command
{
    protected $signature = 'yppr:sync-comp';
    protected $description = 'Sync YPPR079 COMP via api.py';

    public function handle(): int
    {
        $apiPath = base_path('api.py');
        if (!file_exists($apiPath)) {
            $this->error("api.py tidak ditemukan di: {$apiPath}");
            return self::FAILURE;
        }

        $python = $this->detectPython();
        $this->info("Python: {$python}");

        // 8 jam = 28800 detik
        $timeoutSeconds = 28800;

        $cmd = [$python, $apiPath, '--sync_comp', '--timeout', (string) $timeoutSeconds];

        // Timeout proses Symfony juga 8 jam
        $process = new SymfonyProcess($cmd, base_path(), null, null, (float) $timeoutSeconds);

        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        if (!$process->isSuccessful()) {
            $this->error('Sync comp failed');
            return self::FAILURE;
        }

        $this->info('Sync comp done');
        return self::SUCCESS;
    }

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
                    // ignore
                }
            }
        }

        return 'python';
    }
}
