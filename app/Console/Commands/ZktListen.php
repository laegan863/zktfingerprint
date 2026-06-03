<?php

namespace App\Console\Commands;

use App\Models\ZkAttendance;
use App\Models\ZkDevice;
use App\Services\ZKLib;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ZktListen extends Command
{
    protected $signature = 'zkt:listen
                            {--device= : Device key from config/zkteco.php (e.g. main_gate)}
                            {--debug : Log every raw packet to stdout}
                            {--no-reconnect : Exit on first disconnect instead of retrying}';

    protected $description = 'Connect to one ZKTeco terminal and persist live punches as they happen.';

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

                    $insertRow = function () use ($key, $event, $now): void {
                        DB::table('zk_attendance')->insertOrIgnore([
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
                        $insertRow();
                    } catch (Throwable $e) {
                        // MySQL may have silently dropped a long-idle connection
                        // (e.g. overnight). Reconnect once and retry before
                        // giving up — the punch is already safe in the JSONL.
                        try {
                            DB::reconnect();
                            $insertRow();
                            $lastDbPing = $nowTs; // connection is fresh
                        } catch (Throwable $e2) {
                            $this->warn("[$key] DB insert failed (after reconnect): " . $e2->getMessage());
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
}
