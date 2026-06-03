<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // First clean out any existing dupes so the new index can be added.
        \DB::statement("
            DELETE a1 FROM zk_attendance a1
            INNER JOIN zk_attendance a2
                ON a1.device_id   = a2.device_id
               AND a1.user_id     = a2.user_id
               AND a1.device_time = a2.device_time
               AND a1.id > a2.id
        ");

        Schema::table('zk_attendance', function (Blueprint $t) {
            $t->dropUnique('uniq_punch');
        });
        Schema::table('zk_attendance', function (Blueprint $t) {
            $t->unique(['device_id', 'user_id', 'device_time'], 'uniq_punch');
        });
    }

    public function down(): void
    {
        Schema::table('zk_attendance', function (Blueprint $t) {
            $t->dropUnique('uniq_punch');
        });
        Schema::table('zk_attendance', function (Blueprint $t) {
            $t->unique(['device_id', 'user_id', 'device_time', 'verify', 'status'], 'uniq_punch');
        });
    }
};
