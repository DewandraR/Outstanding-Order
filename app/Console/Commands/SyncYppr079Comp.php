<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process as SymfonyProcess;

class SyncYppr079Comp extends Command
{
    protected $signature = 'yppr:sync-comp';
    protected $description = 'Sync YPPR079 COMP via api.py (--sync_comp)';

    public function handle(): int
    {
        $apiPath = base_path('api.py');
        if (!file_exists($apiPath)) {
            $this->error("api.py tidak ditemukan di: {$apiPath}");
            return self::FAILURE;
        }

        $python = $this->detectPython();
        $this->info("Python: {$python}");

        // ✅ Timeout untuk python (argumen ke api.py)
        // 8 jam = 28800 detik
        $pythonTimeoutSeconds = 8 * 3600;

        // ✅ Timeout untuk Symfony Process (Laravel) - dibuat lebih besar dari python supaya tidak memotong duluan
        // 10 jam = 36000 detik
        $symfonyTimeoutSeconds = 10 * 3600;
        //$symfonyTimeoutSeconds = 12 * 3600;

        $cmd = [
            $python,
            $apiPath,
            '--sync_comp',
            '--timeout',
            (string) $pythonTimeoutSeconds,
        ];

        $process = new SymfonyProcess(
            $cmd,
            base_path(),  // working directory
            null,         // env
            null,         // input
            (float) $symfonyTimeoutSeconds
        );

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
