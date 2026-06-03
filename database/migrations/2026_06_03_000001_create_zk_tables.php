<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('zk_devices', function (Blueprint $t) {
            $t->string('device_id', 64)->primary();
            $t->string('name', 128);
            $t->string('ip', 64)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('zk_users', function (Blueprint $t) {
            $t->string('user_id', 32)->primary();
            $t->string('name', 128)->nullable();
            $t->string('department', 128)->nullable();
        });

        Schema::create('zk_attendance', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('device_id', 64)->default('main_gate');
            $t->string('user_id', 32);
            $t->dateTime('device_time');
            $t->dateTime('received_at');
            $t->unsignedTinyInteger('verify')->default(0);
            $t->unsignedTinyInteger('status')->default(0);
            $t->unsignedInteger('workcode')->default(0);
            $t->string('raw', 255)->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->unique(['device_id', 'user_id', 'device_time', 'verify', 'status'], 'uniq_punch');
            $t->index('device_id', 'idx_device');
            $t->index('user_id', 'idx_user');
            $t->index('device_time', 'idx_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zk_attendance');
        Schema::dropIfExists('zk_users');
        Schema::dropIfExists('zk_devices');
    }
};
