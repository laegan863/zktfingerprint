<?php

namespace App\Http\Controllers;

use App\Support\ZktLabels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\StreamedResponse;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    public function index()
    {
        $data = DB::table('zk_attendance as a')
            ->leftJoin('zk_users as u', 'a.user_id', '=', 'u.user_id')
            ->leftJoin('zk_devices as d', 'a.device_id', '=', 'd.device_id')
            ->select([
                'a.id', 'a.device_id', 'd.name as device_name',
                'a.user_id', 'u.name as user_name',
                'a.device_time', 'a.received_at',
                'a.verify', 'a.status', 'a.workcode',
            ])
            ->orderByDesc('a.id')
            ->paginate(20);
        return view('attendance-manual', ['data' => $data]);
    }

    public function devices(): JsonResponse
    {
        $rows = DB::table('zk_devices')
            ->orderBy('name')
            ->get(['device_id', 'name'])
            ->map(fn ($r) => ['device_id' => $r->device_id, 'name' => $r->name])
            ->all();
        return response()->json($rows);
    }

    public function recent(Request $req): JsonResponse
    {
        $n       = max(1, min(500, (int) $req->query('n', 50)));
        $sinceId = $req->query('since_id');  // integer ID cursor (preferred)
        $since   = $req->query('since');      // unix-ts fallback (legacy)
        $device  = $req->query('device');

        $q = DB::table('zk_attendance as a')
            ->leftJoin('zk_users as u', 'a.user_id', '=', 'u.user_id')
            ->leftJoin('zk_devices as d', 'a.device_id', '=', 'd.device_id')
            ->select([
                'a.id', 'a.device_id', 'd.name as device_name',
                'a.user_id', 'u.name as user_name',
                'a.device_time', 'a.received_at',
                'a.verify', 'a.status', 'a.workcode',
            ]);

        if ($device) {
            $q->where('a.device_id', $device);
        }

        if ($sinceId !== null && $sinceId !== '') {
            $q->where('a.id', '>', (int) $sinceId);
        } elseif ($since !== null && $since !== '') {
            $q->where('a.received_at', '>', date('Y-m-d H:i:s', (int) $since));
        }

        $rows = $q->orderByDesc('a.id')->get();

        $out = $rows->map(function ($r) {
            $ts = $r->received_at ? (int) strtotime($r->received_at) : 0;
            return [
                'id'          => (int) $r->id,
                'device_id'   => $r->device_id,
                'device_name' => $r->device_name ?: $r->device_id,
                'user_id'     => $r->user_id,
                'user_name'   => $r->user_name,
                'device_time' => $r->device_time,
                'received_at' => $r->received_at,
                'received_ts' => $ts,
                'verify'      => (int) $r->verify,
                'status'      => (int) $r->status,
                'workcode'    => (int) $r->workcode,
                'mode'        => ZktLabels::verify((int) $r->verify),
                'state'       => ZktLabels::status((int) $r->status),
            ];
        })->values()->all();

        return response()->json($out);
    }

    public function stream(Request $req): StreamedResponse
    {
        $device = $req->query('device');
        $lastId = max(0, (int) ($req->header('Last-Event-ID') ?: $req->query('since_id', 0)));

        return response()->stream(function () use ($device, $lastId): void {
            set_time_limit(0);
            ignore_user_abort(false);

            $cursor = $lastId;
            $startedAt = microtime(true);
            $backlogSent = false;

            while (!connection_aborted() && microtime(true) - $startedAt < 1800) {
                $query = DB::table('zk_attendance as a')
                    ->leftJoin('zk_users as u', 'a.user_id', '=', 'u.user_id')
                    ->leftJoin('zk_devices as d', 'a.device_id', '=', 'd.device_id')
                    ->select([
                        'a.id', 'a.device_id', 'd.name as device_name',
                        'a.user_id', 'u.name as user_name',
                        'a.device_time', 'a.received_at',
                        'a.verify', 'a.status', 'a.workcode',
                    ])
                    ->where('a.id', '>', $cursor)
                    ->when($device, fn ($q) => $q->where('a.device_id', $device))
                    ->orderBy('a.id')
                    ->limit($backlogSent ? 100 : 50);

                $rows = $query->get();
                $backlogSent = true;

                foreach ($rows as $row) {
                    $event = $this->attendancePayload($row);
                    $cursor = (int) $row->id;
                    echo "id: {$cursor}\n";
                    echo "event: attendance\n";
                    echo 'data: ' . json_encode($event, JSON_UNESCAPED_SLASHES) . "\n\n";
                }

                if ($rows->isEmpty()) {
                    echo ": heartbeat\n\n";
                }

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
                usleep(500000);
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-store, must-revalidate',
            'Connection'        => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function attendancePayload(object $r): array
    {
        $ts = $r->received_at ? (int) strtotime($r->received_at) : 0;

        return [
            'id'          => (int) $r->id,
            'device_id'   => $r->device_id,
            'device_name' => $r->device_name ?: $r->device_id,
            'user_id'     => $r->user_id,
            'user_name'   => $r->user_name,
            'device_time' => $r->device_time,
            'received_at' => $r->received_at,
            'received_ts' => $ts,
            'verify'      => (int) $r->verify,
            'status'      => (int) $r->status,
            'workcode'    => (int) $r->workcode,
            'mode'        => ZktLabels::verify((int) $r->verify),
            'state'       => ZktLabels::status((int) $r->status),
        ];
    }
}
