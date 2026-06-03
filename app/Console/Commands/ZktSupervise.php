<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ZktSupervise extends Command
{
    protected $signature = 'zkt:supervise
                            {--windows : Spawn each listener in its own cmd.exe window (Windows only)}';

    protected $description = 'Spawn one zkt:listen process per configured device and auto-restart on crash.';

    public function handle(): int
    {
        $devices = config('zkteco.devices', []);
        if (!$devices) {
            $this->error('No devices configured in config/zkteco.php.');
            return self::FAILURE;
        }

        $delay      = (int) config('zkteco.supervisor.restart_delay', 3);
        $phpBin     = PHP_BINARY;
        $artisan    = base_path('artisan');
        $useWindows = (bool) $this->option('windows') && stripos(PHP_OS, 'WIN') === 0;

        if ($useWindows) {
            foreach (array_keys($devices) as $key) {
                $cmd = sprintf('start "ZKT %s" cmd /k "%s" "%s" zkt:listen --device=%s',
                    $key, $phpBin, $artisan, escapeshellarg($key));
                $this->info("Spawning window: $key");
                pclose(popen($cmd, 'r'));
            }
            $this->info('All listener windows launched. They run independently — close their windows to stop.');
            return self::SUCCESS;
        }

        $procs = [];
        foreach (array_keys($devices) as $key) {
            $procs[$key] = $this->spawn($phpBin, $artisan, $key);
        }

        // Trap signals if pcntl available.
        $running = true;
        if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $stop = function () use (&$running) { $running = false; };
            pcntl_signal(SIGINT,  $stop);
            pcntl_signal(SIGTERM, $stop);
        }

        $this->info('Supervisor running. Press Ctrl+C to stop.');
        while ($running) {
            foreach ($procs as $key => &$p) {
                if (!is_resource($p['proc'])) continue;
                $status = proc_get_status($p['proc']);

                // Drain stdout/stderr so children never block.
                foreach (['out', 'err'] as $which) {
                    $stream = $p[$which] ?? null;
                    if (!is_resource($stream)) continue;
                    $chunk = @stream_get_contents($stream);
                    if ($chunk !== false && $chunk !== '') {
                        foreach (preg_split('/\r?\n/', rtrim($chunk, "\r\n")) as $ln) {
                            if ($ln !== '') $this->line("[$key] $ln");
                        }
                    }
                }

                if (!$status['running']) {
                    $this->warn("[$key] exited (code {$status['exitcode']}). Restarting in {$delay}s...");
                    foreach (['out','err'] as $w) { if (is_resource($p[$w] ?? null)) @fclose($p[$w]); }
                    @proc_close($p['proc']);
                    sleep($delay);
                    if (!$running) break 2;
                    $p = $this->spawn($phpBin, $artisan, $key);
                }
            }
            unset($p);
            usleep(300000);
        }

        $this->info('Stopping listeners...');
        foreach ($procs as $key => $p) {
            if (is_resource($p['proc'])) {
                proc_terminate($p['proc']);
                foreach (['out','err'] as $w) { if (is_resource($p[$w] ?? null)) @fclose($p[$w]); }
                @proc_close($p['proc']);
            }
        }
        return self::SUCCESS;
    }

    private function spawn(string $phpBin, string $artisan, string $key): array
    {
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $cmd = [$phpBin, $artisan, 'zkt:listen', "--device=$key"];
        $proc = proc_open($cmd, $descriptor, $pipes, base_path());
        if (!is_resource($proc)) {
            $this->error("[$key] Failed to spawn listener.");
            return ['proc' => null];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        @fclose($pipes[0]);
        $this->info("[$key] Listener spawned.");
        return ['proc' => $proc, 'out' => $pipes[1], 'err' => $pipes[2]];
    }
}
