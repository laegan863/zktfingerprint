<?php

namespace App\Services;

use Generator;
use RuntimeException;

/**
 * ZKLib — Native PHP ZKTeco protocol client (no Composer deps).
 *
 * Subscribes via CMD_REG_EVENT and yields punches as soon as the device
 * pushes them. Supports TCP (modern firmware) and UDP (legacy) with
 * auto-detection. See lib/ZKLib.php in the native project for details.
 */
class ZKLib
{
    const CMD_CONNECT       = 1000;
    const CMD_EXIT          = 1001;
    const CMD_ENABLEDEVICE  = 1002;
    const CMD_DISABLEDEVICE = 1003;
    const CMD_ACK_OK        = 2000;
    const CMD_ACK_ERROR     = 2001;
    const CMD_ACK_UNAUTH    = 2005;
    const CMD_AUTH          = 1102;

    const CMD_REG_EVENT     = 500;
    const EF_ATTLOG         = 1;
    const EF_ALL            = 0xFFFF;

    const START_TAG = "\x50\x50\x82\x7d";

    private string $ip;
    private int    $port;
    private int    $commKey;
    private int    $timeout;
    private string $transport;
    private bool   $isTcp = true;

    /** @var resource|\Socket|null */
    private $sock = null;
    private int $sessionId = 0;
    private int $replyId   = 0;

    public function __construct(string $ip, int $port = 4370, int $commKey = 0, int $timeout = 5, string $transport = 'auto')
    {
        $this->ip        = $ip;
        $this->port      = $port;
        $this->commKey   = $commKey;
        $this->timeout   = $timeout;
        $this->transport = strtolower($transport);
    }

    public function connect(): bool
    {
        $modes = match ($this->transport) {
            'tcp' => ['tcp'],
            'udp' => ['udp'],
            default => ['tcp', 'udp'],
        };

        $lastErr = null;
        foreach ($modes as $mode) {
            try {
                $this->openSocket($mode);
                $this->sessionId = 0;
                $this->replyId   = 0;

                $resp = $this->command(self::CMD_CONNECT);
                if ($resp === null) {
                    throw new RuntimeException("No response from device at {$this->ip}:{$this->port} ({$mode})");
                }
                $this->sessionId = $resp['session_id'];

                if ($resp['command'] === self::CMD_ACK_UNAUTH) {
                    if (!$this->authenticate()) {
                        throw new RuntimeException('Authentication failed (wrong comm key).');
                    }
                } elseif ($resp['command'] !== self::CMD_ACK_OK) {
                    throw new RuntimeException('Device refused connection (code ' . $resp['command'] . ')');
                }
                return true;
            } catch (\Throwable $e) {
                $lastErr = $e;
                if ($this->sock) { @socket_close($this->sock); $this->sock = null; }
            }
        }
        throw $lastErr ?? new RuntimeException('Unable to connect to device.');
    }

    public function transport(): string { return $this->isTcp ? 'tcp' : 'udp'; }

    private function openSocket(string $mode): void
    {
        $this->isTcp = ($mode === 'tcp');
        if ($this->isTcp) {
            $this->sock = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if (!$this->sock) {
                throw new RuntimeException('socket_create(TCP) failed: ' . socket_strerror(socket_last_error()));
            }
            socket_set_option($this->sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $this->timeout, 'usec' => 0]);
            socket_set_option($this->sock, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $this->timeout, 'usec' => 0]);
            if (!@socket_connect($this->sock, $this->ip, $this->port)) {
                $err = socket_strerror(socket_last_error($this->sock));
                @socket_close($this->sock); $this->sock = null;
                throw new RuntimeException("TCP connect to {$this->ip}:{$this->port} failed: $err");
            }
        } else {
            $this->sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if (!$this->sock) {
                throw new RuntimeException('socket_create(UDP) failed: ' . socket_strerror(socket_last_error()));
            }
            socket_set_option($this->sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $this->timeout, 'usec' => 0]);
            socket_set_option($this->sock, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $this->timeout, 'usec' => 0]);
        }
    }

    public function disconnect(): void
    {
        if ($this->sock) {
            try { $this->command(self::CMD_EXIT); } catch (\Throwable $e) {}
            @socket_close($this->sock);
            $this->sock = null;
        }
    }

    public function disableDevice(): void { $this->command(self::CMD_DISABLEDEVICE, "\x00\x00"); }
    public function enableDevice():  void { $this->command(self::CMD_ENABLEDEVICE); }

    public function liveCapture(?callable $rawLogger = null): Generator
    {
        $this->command(self::CMD_REG_EVENT, pack('V', self::EF_ALL));

        // Use a bounded SO_RCVTIMEO as a safety net. Real waiting is done
        // through socket_select() below so we can fire periodic keep-alives.
        socket_set_option($this->sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 5, 'usec' => 0]);

        // ZKTeco firmware silently drops the event stream after an idle
        // period (commonly ~60s on TCP). Without traffic we never notice,
        // so we ping with CMD_REG_EVENT and watch how long since we've
        // heard from the device. The watchdog also catches half-open TCP
        // links where the device went away without a FIN.
        $keepAliveEvery  = 25;   // seconds between keep-alive re-subscribes
        $maxSilence      = 90;   // seconds with no traffic at all = dead link
        $lastKeepAlive   = time();
        $lastDeviceTraffic = time();

        while (true) {
            // Wait up to 1s for data; this gives us a regular tick for
            // keep-alive / watchdog checks without busy-spinning.
            $read = [$this->sock]; $write = null; $except = null;
            $ready = @socket_select($read, $write, $except, 1, 0);

            if ($ready === false) {
                throw new RuntimeException('socket_select failed: ' . socket_strerror(socket_last_error($this->sock)));
            }

            if ($ready > 0) {
                if ($this->isTcp) {
                    $pkt = $this->recvTcpPacket();
                    if ($pkt === null) {
                        // Peer closed (FIN) or a malformed/short read on a
                        // ready socket = dead connection. Bail so the
                        // caller can reconnect instead of spinning silently.
                        throw new RuntimeException('TCP connection to device lost (recv returned 0).');
                    }
                    $hex = '(tcp)';
                } else {
                    $buf = ''; $from = ''; $fp = 0;
                    $n = @socket_recvfrom($this->sock, $buf, 1032, 0, $from, $fp);
                    if ($n === false || $n < 8) { continue; }
                    $pkt = $this->parsePacket($buf);
                    if ($pkt === null) continue;
                    $hex = bin2hex($buf);
                }

                $lastDeviceTraffic = time();

                if ($rawLogger) { $rawLogger($pkt, $hex); }

                $event = $this->decodeAttEvent($pkt['data']);
                if ($event !== null && $pkt['command'] !== self::CMD_ACK_OK) {
                    yield $event;
                }
            }

            $now = time();

            // Periodic keep-alive: re-register for events. The ACK to this
            // also counts as traffic, so a healthy link will reset the
            // silence counter every $keepAliveEvery seconds.
            if ($now - $lastKeepAlive >= $keepAliveEvery) {
                try {
                    $resp = $this->command(self::CMD_REG_EVENT, pack('V', self::EF_ALL));
                    if ($resp !== null) {
                        $lastDeviceTraffic = $now;
                    }
                } catch (\Throwable $e) {
                    throw new RuntimeException('Keep-alive failed: ' . $e->getMessage(), 0, $e);
                }
                $lastKeepAlive = $now;
            }

            // Watchdog: if the device hasn't said anything in a long time,
            // assume the link is dead and let the command exit so it can
            // be restarted by the supervisor / scheduler.
            if ($now - $lastDeviceTraffic > $maxSilence) {
                throw new RuntimeException(
                    "Device silent for " . ($now - $lastDeviceTraffic) . "s; assuming dead link."
                );
            }
        }
    }

    private function authenticate(): bool
    {
        $key = $this->makeCommKey($this->commKey, $this->sessionId);
        $resp = $this->command(self::CMD_AUTH, pack('V', $key));
        return $resp !== null && $resp['command'] === self::CMD_ACK_OK;
    }

    private function makeCommKey(int $key, int $sessionId, int $ticks = 50): int
    {
        $k = 0;
        for ($i = 0; $i < 32; $i++) {
            if (($key & (1 << $i)) !== 0) {
                $k = ($k << 1 | 1) & 0xFFFFFFFF;
            } else {
                $k = ($k << 1) & 0xFFFFFFFF;
            }
        }
        $k = ($k + $sessionId) & 0xFFFFFFFF;
        $b = pack('V', $k);
        $b = chr(ord($b[0]) ^ ord('Z'))
           . chr(ord($b[1]) ^ ord('K'))
           . chr(ord($b[2]) ^ ord('S'))
           . chr(ord($b[3]) ^ ord('O'));
        $arr = unpack('v2', $b);
        $b   = pack('v2', $arr[2], $arr[1]);
        $B   = $ticks & 0xFF;
        $out = chr(ord($b[0]) ^ $B)
             . chr(ord($b[1]) ^ $B)
             . chr($B)
             . chr(ord($b[3]) ^ $B);
        return unpack('V', $out)[1];
    }

    private function command(int $cmd, string $data = ''): ?array
    {
        $this->replyId = ($this->replyId + 1) & 0xFFFF;
        $inner = $this->buildInner($cmd, $this->sessionId, $this->replyId, $data);

        if ($this->isTcp) {
            $tcpMagic = "\x50\x50\x82\x7d";
            $pkt = $tcpMagic . pack('V', strlen($inner)) . $inner;
            $sent = @socket_send($this->sock, $pkt, strlen($pkt), 0);
            if ($sent === false) {
                throw new RuntimeException('socket_send failed: ' . socket_strerror(socket_last_error($this->sock)));
            }
            return $this->recvTcpPacket();
        }

        $pkt = self::START_TAG . pack('V', strlen($inner)) . $inner;
        if (@socket_sendto($this->sock, $pkt, strlen($pkt), 0, $this->ip, $this->port) === false) {
            throw new RuntimeException('socket_sendto failed: ' . socket_strerror(socket_last_error($this->sock)));
        }
        $buf = '';
        $from = ''; $fp = 0;
        $n = @socket_recvfrom($this->sock, $buf, 1032, 0, $from, $fp);
        if ($n === false || $n < 8) { return null; }
        return $this->parsePacket($buf);
    }

    private function recvTcpPacket(): ?array
    {
        $hdr = $this->recvAll(8);
        if ($hdr === null) return null;
        $innerLen = unpack('V', substr($hdr, 4, 4))[1];
        if ($innerLen <= 0 || $innerLen > 1024 * 1024) return null;
        $inner = $this->recvAll($innerLen);
        if ($inner === null || strlen($inner) < 8) return null;

        $h = unpack('vcmd/vchk/vsid/vrid', substr($inner, 0, 8));
        return [
            'command'    => $h['cmd'],
            'checksum'   => $h['chk'],
            'session_id' => $h['sid'],
            'reply_id'   => $h['rid'],
            'data'       => substr($inner, 8),
        ];
    }

    private function recvAll(int $n): ?string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = '';
            $r = @socket_recv($this->sock, $chunk, $n - strlen($buf), MSG_WAITALL);
            if ($r === false || $r === 0) {
                $r2 = @socket_recv($this->sock, $chunk, $n - strlen($buf), 0);
                if ($r2 === false || $r2 === 0) return null;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    private function buildInner(int $cmd, int $sessionId, int $replyId, string $data): string
    {
        $header = pack('vvvv', $cmd, 0, $sessionId, $replyId);
        $sum    = $this->checksum16($header . $data);
        return pack('vvvv', $cmd, $sum, $sessionId, $replyId) . $data;
    }

    private function checksum16(string $buf): int
    {
        $len = strlen($buf);
        $sum = 0;
        $i = 0;
        while ($i + 1 < $len) {
            $sum += (ord($buf[$i]) | (ord($buf[$i + 1]) << 8));
            if ($sum > 0xFFFF) { $sum -= 0xFFFF; }
            $i += 2;
        }
        if ($i < $len) {
            $sum += ord($buf[$i]);
            if ($sum > 0xFFFF) { $sum -= 0xFFFF; }
        }
        return (~$sum) & 0xFFFF;
    }

    private function parsePacket(string $buf): ?array
    {
        if (strlen($buf) < 16) return null;
        $inner = substr($buf, 8);
        $h = unpack('vcmd/vchk/vsid/vrid', substr($inner, 0, 8));
        return [
            'command'    => $h['cmd'],
            'checksum'   => $h['chk'],
            'session_id' => $h['sid'],
            'reply_id'   => $h['rid'],
            'data'       => substr($inner, 8),
        ];
    }

    private function decodeAttEvent(string $data): ?array
    {
        $len = strlen($data);
        if ($len < 8) return null;

        if ($len >= 32) {
            $uid    = rtrim(substr($data, 0, 24), "\x00");
            $verify = ord($data[24]);
            $status = ord($data[25]);
            $b      = substr($data, 26, 6);
            $year   = 2000 + ord($b[0]);
            $mon    = ord($b[1]);
            $day    = ord($b[2]);
            $hour   = ord($b[3]);
            $min    = ord($b[4]);
            $sec    = ord($b[5]);
            $wc     = $len >= 36 ? unpack('V', substr($data, 32, 4))[1] : 0;
            if (!checkdate($mon, $day, $year)) return null;
            $ts = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $mon, $day, $hour, $min, $sec);
            if ($uid === '') return null;
            return [
                'user_id'   => $uid,
                'timestamp' => $ts,
                'status'    => $status,
                'verify'    => $verify,
                'workcode'  => $wc,
            ];
        }

        if ($len >= 14) {
            $uid    = rtrim(substr($data, 0, 9), "\x00");
            $verify = ord($data[9]);
            $tsRaw  = unpack('V', substr($data, 10, 4))[1];
            $status = ord($data[14] ?? "\x00");
            $wc     = $len >= 19 ? unpack('V', substr($data, 15, 4))[1] : 0;
            $ts     = $this->decodeZkTime($tsRaw);
        } elseif ($len >= 12) {
            $uid    = rtrim(substr($data, 0, 8), "\x00");
            $verify = ord($data[8] ?? "\x00") ?: 1;
            $tsRaw  = unpack('V', substr($data, 8, 4))[1];
            $status = 0;
            $wc     = 0;
            if (!ctype_print($uid)) {
                $uid = (string) unpack('V', substr($data, 0, 4))[1];
            }
            $ts = $this->decodeZkTime($tsRaw);
        } else {
            $u      = unpack('vuid/Vts/Cstatus/Cverify', $data);
            $uid    = (string) $u['uid'];
            $verify = $u['verify'];
            $status = $u['status'];
            $wc     = 0;
            $ts     = $this->decodeZkTime($u['ts']);
        }

        if ($uid === '' || $ts === null) return null;

        return [
            'user_id'   => $uid,
            'timestamp' => $ts,
            'status'    => $status,
            'verify'    => $verify,
            'workcode'  => $wc,
        ];
    }

    private function decodeZkTime(int $t): ?string
    {
        if ($t <= 0) return null;
        $sec  =  $t        % 60;  $t = intdiv($t, 60);
        $min  =  $t        % 60;  $t = intdiv($t, 60);
        $hour =  $t        % 24;  $t = intdiv($t, 24);
        $day  = ($t        % 31) + 1; $t = intdiv($t, 31);
        $mon  = ($t        % 12) + 1;
        $year = intdiv($t, 12) + 2000;
        if (!checkdate($mon, $day, $year)) return null;
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $mon, $day, $hour, $min, $sec);
    }
}
