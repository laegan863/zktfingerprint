<?php

namespace App\Console\Commands;

use App\Models\ZkAttendance;
use App\Models\ZkDevice;
use App\Services\ZKLib;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Throwable;

class ZktListen extends Command
{
    protected $signature = 'zkt:listen
                            {--device= : Device key from config/zkteco.php (e.g. main_gate)}
                            {--debug : Log every raw packet to stdout}
                            {--no-reconnect : Exit on first disconnect instead of retrying}
                            {--wait-db= : Seconds to wait for DB at startup (0 = config default, -1 = wait forever)}';

    protected $description = 'Connect to one ZKTeco terminal and persist live punches as they happen.';

    protected function configure(): void
    {
        parent::configure();
        $this->setAliases(['ztk:listen']);
    }

    public function handle(): int
    {
        // Must be 0 for a long-running daemon; be explicit even though PHP CLI
        // defaults to no limit, because some environments override php.ini.
        set_time_limit(0);

        $key = $this->option('device');
        if (!$key) {
            $this->error('Missing --device=<key>. Available: ' . implode(', ', array_keys(config('zkteco.devices', []))));
            return self::INVALID;
        }

        $cfg = config("zkteco.devices.$key");
        if (!$cfg) {
            $this->error("Unknown device '$key'. Check config/zkteco.php.");
            return self::INVALID;
        }

        $debug      = (bool) $this->option('debug');
        $reconnect  = ! (bool) $this->option('no-reconnect');

        if (!$this->preflight($key, $cfg['ip'] ?? null, $this->resolveDbWaitSeconds())) {
            return self::FAILURE;
        }

        $storageDir = config('zkteco.storage_dir');
        if (!is_dir($storageDir)) { @mkdir($storageDir, 0777, true); }
        $jsonl  = $storageDir . DIRECTORY_SEPARATOR . "events-$key.jsonl";
        $pidF   = $storageDir . DIRECTORY_SEPARATOR . "listener-$key.pid";
        @file_put_contents($pidF, (string) getmypid());

        // Register device row.
        ZkDevice::updateOrCreate(
            ['device_id' => $key],
            ['name' => $cfg['name'] ?? $key, 'ip' => $cfg['ip']]
        );

        $this->info("[$key] Connecting to {$cfg['ip']}:{$cfg['port']} ...");

        // In-memory dedupe: ZK firmware tends to push the same punch several
        // times (different command codes / slight verify-status variants) over
        // a few seconds. We collapse anything with the same user+timestamp
        // arriving inside DEDUPE_WINDOW seconds into a single row.
        $DEDUPE_WINDOW = 15;     // seconds
        $recent        = [];      // sig => unix_ts when first seen

        $backoff = 2;             // seconds, doubles on consecutive failures
        $maxBackoff = 60;

        // DB keepalive: ping every 30 min so MySQL never considers the
        // connection idle long enough to drop it (default wait_timeout = 8h,
        // but cloud/shared hosts often set it to 1h or less).
        $dbPingEvery   = 1800;   // seconds
        $lastDbPing    = time();

        while (true) {
            $zk = new ZKLib(
                $cfg['ip'],
                (int) ($cfg['port'] ?? 4370),
                (int) ($cfg['comm_key'] ?? 0),
                (int) ($cfg['timeout'] ?? 5),
                (string) ($cfg['transport'] ?? 'auto')
            );

            try {
                $zk->connect();
            } catch (Throwable $e) {
                $this->error("[$key] Connect failed: " . $e->getMessage());
                if (!$reconnect) return self::FAILURE;
                $this->warn("[$key] Retrying in {$backoff}s ...");
                sleep($backoff);
                $backoff = min($maxBackoff, $backoff * 2);
                continue;
            }

            $this->info("[$key] Connected via {$zk->transport()}. Waiting for punches... (Ctrl+C to stop)");
            $backoff = 2; // reset on successful connect

            $rawLogger = $debug ? function (array $pkt, string $hex) use ($key) {
                $this->line("[$key] cmd={$pkt['command']} len=" . strlen($pkt['data']) . " hex=" . substr($hex, 0, 120));
            } : null;

            try {
                foreach ($zk->liveCapture($rawLogger) as $event) {
                    $now   = date('Y-m-d H:i:s');
                    $nowTs = time();

                    // Periodic DB keepalive — runs at most once per tick
                    // (generator yields on every received packet or keep-alive ACK).
                    if ($nowTs - $lastDbPing >= $dbPingEvery) {
                        try { DB::select('SELECT 1'); } catch (Throwable $ignored) {
                            try { DB::reconnect(); } catch (Throwable $ignored2) {}
                        }
                        $lastDbPing = $nowTs;
                    }

                    // Signature ignores verify/status because the device sometimes
                    // emits both a "fingerprint verified" and an "attendance log"
                    // packet for the same physical punch with differing fields.
                    $sig = $key . '|' . $event['user_id'] . '|' . $event['timestamp'];

                    // Purge old entries.
                    foreach ($recent as $s => $t) {
                        if ($nowTs - $t > $DEDUPE_WINDOW) unset($recent[$s]);
                    }
                    if (isset($recent[$sig])) {
                        if ($debug) {
                            $this->line("[$key] dup ignored user={$event['user_id']} time={$event['timestamp']}");
                        }
                        continue;
                    }
                    $recent[$sig] = $nowTs;

                    $line = json_encode($event + ['device' => $key, 'received_at' => $now], JSON_UNESCAPED_SLASHES) . "\n";
                    @file_put_contents($jsonl, $line, FILE_APPEND | LOCK_EX);

                    $insertRow = function () use ($key, $event, $now): int {
                        return (int) DB::table('zk_attendance')->insertOrIgnore([
                            'device_id'   => $key,
                            'user_id'     => $event['user_id'],
                            'device_time' => $event['timestamp'],
                            'received_at' => $now,
                            'verify'      => $event['verify'],
                            'status'      => $event['status'],
                            'workcode'    => $event['workcode'],
                        ]);
                    };
                    try {
                        $affected = $insertRow();
                        $this->reportSuppressedInsertIfNeeded($key, $event, $affected);
                    } catch (Throwable $e) {
                        // MySQL may have silently dropped a long-idle connection
                        // (e.g. overnight). Reconnect once and retry before
                        // giving up — the punch is already safe in the JSONL.
                        try {
                            DB::reconnect();
                            $affected = $insertRow();
                            $this->reportSuppressedInsertIfNeeded($key, $event, $affected);
                            $lastDbPing = $nowTs; // connection is fresh
                        } catch (Throwable $e2) {
                            $this->warn("[$key] DB insert failed (after reconnect): " . $e2->getMessage());
                            Log::warning('ZKT attendance DB insert failed after reconnect', [
                                'device' => $key,
                                'user_id' => $event['user_id'] ?? null,
                                'device_time' => $event['timestamp'] ?? null,
                                'error' => $e2->getMessage(),
                            ]);
                        }
                    }

                    $this->line(sprintf(
                        "[%s] %s user=%s time=%s verify=%d status=%d wc=%d",
                        $key, $now, $event['user_id'], $event['timestamp'],
                        $event['verify'], $event['status'], $event['workcode']
                    ));
                }
            } catch (Throwable $e) {
                $this->error("[$key] Listener error: " . $e->getMessage());
                try { $zk->disconnect(); } catch (Throwable $ignored) {}
                if (!$reconnect) return self::FAILURE;
                $this->warn("[$key] Reconnecting in {$backoff}s ...");
                sleep($backoff);
                $backoff = min($maxBackoff, $backoff * 2);
                continue;
            }

            // liveCapture() is an infinite loop; reaching here means it
            // returned cleanly (shouldn't happen). Reconnect just in case.
            try { $zk->disconnect(); } catch (Throwable $ignored) {}
            if (!$reconnect) return self::SUCCESS;
        }
    }

    private function preflight(string $key, ?string $ip, int $waitDbSeconds): bool
    {
        if (!extension_loaded('sockets')) {
            $this->error('Missing PHP extension: sockets. Enable ext-sockets for CLI PHP used by artisan.');
            return false;
        }

        $dbDriver = (string) config('database.default');
        if (in_array($dbDriver, ['mysql', 'mariadb'], true) && !extension_loaded('pdo_mysql')) {
            $this->error('Missing PHP extension: pdo_mysql. Enable it in CLI php.ini.');
            return false;
        }
        if ($dbDriver === 'pgsql' && !extension_loaded('pdo_pgsql')) {
            $this->error('Missing PHP extension: pdo_pgsql. Enable it in CLI php.ini.');
            return false;
        }

        if (!$this->waitForDatabase($key, $waitDbSeconds)) {
            return false;
        }

        if (!$ip) {
            $this->error("[$key] Missing device IP in config/zkteco.php or env (ZKT_*_IP).");
            return false;
        }

        return true;
    }

    private function resolveDbWaitSeconds(): int
    {
        $raw = $this->option('wait-db');
        if ($raw === null || $raw === '') {
            return (int) config('zkteco.supervisor.wait_db_seconds', 120);
        }

        $seconds = (int) $raw;
        if ($seconds === 0) {
            return (int) config('zkteco.supervisor.wait_db_seconds', 120);
        }

        return $seconds;
    }

    private function waitForDatabase(string $key, int $waitSeconds): bool
    {
        $retryDelay = max(1, (int) config('zkteco.supervisor.db_retry_delay', 3));
        $deadline = $waitSeconds < 0 ? null : time() + max(0, $waitSeconds);
        $firstFailure = true;

        while (true) {
            try {
                DB::select('SELECT 1');
                DB::table('zk_attendance')->limit(1)->get();
                return true;
            } catch (Throwable $e) {
                if ($deadline !== null && time() >= $deadline) {
                    $this->error("[$key] Database preflight failed: " . $e->getMessage());
                    $this->warn('Check DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD and make sure migrations ran on server.');
                    return false;
                }

                if ($firstFailure) {
                    $mode = $waitSeconds < 0 ? 'forever' : "up to {$waitSeconds}s";
                    $this->warn("[$key] Database is not ready yet; waiting {$mode} before giving up.");
                    $firstFailure = false;
                }

                $remaining = $deadline === null ? 'unknown' : max(0, $deadline - time()) . 's';
                $this->warn("[$key] DB unavailable ({$e->getMessage()}). Retrying in {$retryDelay}s (remaining: {$remaining}).");
                sleep($retryDelay);
            }
        }
    }

    private function reportSuppressedInsertIfNeeded(string $key, array $event, int $affected): void
    {
        if ($affected > 0) {
            return;
        }

        $isKnownDuplicate = DB::table('zk_attendance')
            ->where('device_id', $key)
            ->where('user_id', $event['user_id'])
            ->where('device_time', $event['timestamp'])
            ->exists();

        if ($isKnownDuplicate) {
            return;
        }

        $msg = sprintf(
            '[%s] insertOrIgnore affected=0 but row is not an existing duplicate (user=%s, device_time=%s).',
            $key,
            (string) ($event['user_id'] ?? ''),
            (string) ($event['timestamp'] ?? '')
        );

        $this->warn($msg);
        Log::warning('ZKT insert suppressed unexpectedly', [
            'device' => $key,
            'user_id' => $event['user_id'] ?? null,
            'device_time' => $event['timestamp'] ?? null,
            'verify' => $event['verify'] ?? null,
            'status' => $event['status'] ?? null,
            'workcode' => $event['workcode'] ?? null,
        ]);
    }
}
