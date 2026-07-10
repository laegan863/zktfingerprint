<?php

namespace App\Http\Controllers;

use App\Support\ZktLabels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    public function index()
    {
        return view('attendance');
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
            // Integer cursor: exact, no timezone or second-precision ambiguity.
            $q->where('a.id', '>', (int) $sinceId);
        } elseif ($since !== null && $since !== '') {
            // Legacy timestamp filter kept for any external callers.
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
}
