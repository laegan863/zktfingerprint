<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZkUser extends Model
{
    protected $table = 'zk_users';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = ['user_id', 'name', 'department'];
}
