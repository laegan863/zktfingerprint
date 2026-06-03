<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZkAttendance extends Model
{
    protected $table = 'zk_attendance';
    public $timestamps = false;

    protected $fillable = [
        'device_id', 'user_id', 'device_time', 'received_at',
        'verify', 'status', 'workcode', 'raw',
    ];

    protected $casts = [
        'device_time' => 'datetime',
        'received_at' => 'datetime',
        'created_at'  => 'datetime',
        'verify'      => 'int',
        'status'      => 'int',
        'workcode'    => 'int',
    ];
}
