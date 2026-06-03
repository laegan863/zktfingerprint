<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZkDevice extends Model
{
    protected $table = 'zk_devices';
    protected $primaryKey = 'device_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = ['device_id', 'name', 'ip', 'created_at'];
}
