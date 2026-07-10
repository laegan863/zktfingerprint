<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo 'zk_attendance count: '.App\Models\ZkAttendance::count().PHP_EOL;
$last = App\Models\ZkAttendance::orderByDesc('id')->first();
echo 'Last row: '.($last ? json_encode($last->toArray()) : 'none').PHP_EOL;

echo 'zk_devices:'.PHP_EOL;
foreach (App\Models\ZkDevice::all(['device_id','name','ip','created_at']) as $d) {
    echo $d->device_id.' | '.$d->ip.' | '.$d->created_at.PHP_EOL;
}
