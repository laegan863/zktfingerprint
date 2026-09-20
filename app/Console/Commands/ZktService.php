<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Manages a Windows Service (via WinSW) that runs `php artisan zkt:supervise`.
 *
 * WinSW (https://github.com/winsw/winsw) wraps a console application as a
 * proper Windows Service: it starts on boot, and restarts it automatically
 * if it crashes — no logged-in user or scheduled task required.
 */
class ZktService extends Command
{
    protected $signature = 'zkt:service
                            {action=install : install|uninstall|start|stop|restart|status}
                            {--name= : Windows service id (default from config/zkteco.php)}
                            {--display= : Service display name (default from config/zkteco.php)}
                            {--winsw= : Path to a WinSW executable to copy in as the service exe}
                            {--php= : Absolute path to php.exe used by the service}
                            {--artisan= : Absolute path to artisan file used by the service}
                            {--force : Overwrite an already-generated service exe/config}';

    protected $description = 'Install/manage a Windows Service (WinSW) that runs zkt:supervise and auto-restarts on crash or reboot.';

    protected function configure(): void
    {
        parent::configure();
        $this->setAliases(['ztk:service']);
    }

    public function handle(): int
    {
        if (stripos(PHP_OS, 'WIN') !== 0) {
            $this->error('zkt:service only supports Windows (WinSW wraps Windows Services).');
            return self::FAILURE;
        }

        $action = strtolower((string) $this->argument('action'));
        $valid  = ['install', 'uninstall', 'start', 'stop', 'restart', 'status'];
        if (!in_array($action, $valid, true)) {
            $this->error("Invalid action '{$action}'. Use one of: " . implode(', ', $valid));
            return self::INVALID;
        }

        $name    = (string) ($this->option('name') ?: config('zkteco.service.name', 'ZktSupervisor'));
        $display = (string) ($this->option('display') ?: config('zkteco.service.display_name', 'ZKT Supervisor'));
        $winswDir = (string) config('zkteco.service.winsw_dir', base_path('winsw'));

        if (!is_dir($winswDir) && !@mkdir($winswDir, 0777, true) && !is_dir($winswDir)) {
            $this->error("Could not create WinSW directory: {$winswDir}");
            return self::FAILURE;
        }

        $exePath = $winswDir . DIRECTORY_SEPARATOR . $name . '.exe';
        $xmlPath = $winswDir . DIRECTORY_SEPARATOR . $name . '.xml';
        $force   = (bool) $this->option('force');

        if ($action === 'install') {
            $phpBin  = (string) ($this->option('php') ?: config('zkteco.supervisor.php_bin', PHP_BINARY));
            $artisan = (string) ($this->option('artisan') ?: config('zkteco.supervisor.artisan_path', base_path('artisan')));
            $workDir = dirname($artisan) ?: base_path();

            if (!is_file($artisan)) {
                $this->error("Artisan file not found: {$artisan}");
                return self::FAILURE;
            }
            if ((str_contains($phpBin, DIRECTORY_SEPARATOR) || str_contains($phpBin, '/')) && !is_file($phpBin)) {
                $this->error("PHP binary not found: {$phpBin}");
                return self::FAILURE;
            }

            if (!is_file($xmlPath) || $force) {
                file_put_contents($xmlPath, $this->buildXml($name, $display, $phpBin, $artisan, $workDir));
                $this->info("Wrote service config: {$xmlPath}");
            } else {
                $this->line("Service config already exists (use --force to overwrite): {$xmlPath}");
            }

            if (!is_file($exePath) || $force) {
                $source = (string) $this->option('winsw');
                if ($source) {
                    if (!is_file($source)) {
                        $this->error("--winsw path not found: {$source}");
                        return self::FAILURE;
                    }
                    if (!@copy($source, $exePath)) {
                        $this->error("Failed to copy WinSW executable to: {$exePath}");
                        return self::FAILURE;
                    }
                    $this->info("Copied WinSW executable to: {$exePath}");
                } else {
                    $this->error("Service executable not found: {$exePath}");
                    $this->warn('Download WinSW.exe from https://github.com/winsw/winsw/releases and either:');
                    $this->warn("  - re-run with --winsw=\"C:\\path\\to\\WinSW.exe\", or");
                    $this->warn("  - manually copy/rename it to: {$exePath}");
                    return self::FAILURE;
                }
            }
        }

        if (!is_file($exePath)) {
            $this->error("Service executable not found: {$exePath}");
            $this->warn('Run "php artisan zkt:service install --winsw=..." first.');
            return self::FAILURE;
        }

        if ($action === 'install') {
            return $this->runWinsw($exePath, ['install']);
        }

        return $this->runWinsw($exePath, [$action]);
    }

    private function runWinsw(string $exePath, array $args): int
    {
        $process = new Process([$exePath, ...$args]);
        $process->setTimeout(60);
        $process->run(function (string $type, string $buffer): void {
            foreach (preg_split('/\r?\n/', rtrim($buffer, "\r\n")) as $line) {
                if ($line !== '') {
                    $this->line($line);
                }
            }
        });

        if (!$process->isSuccessful()) {
            $this->error('WinSW command failed with exit code ' . $process->getExitCode());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function buildXml(string $id, string $display, string $phpBin, string $artisan, string $workDir): string
    {
        $logDir = rtrim($workDir, '\\/') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'winsw';

        $e = fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return <<<XML
        <service>
          <id>{$e($id)}</id>
          <name>{$e($display)}</name>
          <description>Runs php artisan zkt:supervise to keep ZKTeco device listeners alive. Auto-starts on boot and restarts on crash.</description>
          <executable>{$e($phpBin)}</executable>
          <arguments>"{$e($artisan)}" zkt:supervise</arguments>
          <workingdirectory>{$e($workDir)}</workingdirectory>
          <logpath>{$e($logDir)}</logpath>
          <log mode="roll-by-size">
            <sizeThreshold>10240</sizeThreshold>
            <keepFiles>8</keepFiles>
          </log>
          <onfailure action="restart" delay="5 sec"/>
          <onfailure action="restart" delay="10 sec"/>
          <resetfailure>1 hour</resetfailure>
          <startmode>Automatic</startmode>
          <stoptimeout>15 sec</stoptimeout>
          <stopparentprocessfirst>true</stopparentprocessfirst>
        </service>
        XML;
    }
}
