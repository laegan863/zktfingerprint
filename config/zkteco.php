<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Devices
    |--------------------------------------------------------------------------
    | One entry per physical ZKTeco terminal. The key (e.g. "main_gate") is
    | used as device_id in the database and is passed to `php artisan
    | zkt:listen --device=main_gate`.
    */
    'devices' => [
        'main_gate' => [
            'name'      => env('ZKT_MAIN_GATE_NAME', 'Main Gate'),
            'ip'        => env('ZKT_MAIN_GATE_IP', '172.16.111.89'),
            'port'      => (int) env('ZKT_MAIN_GATE_PORT', 4370),
            'comm_key'  => (int) env('ZKT_MAIN_GATE_KEY', 0),
            'timeout'   => 5,
            'transport' => 'auto', // tcp | udp | auto
        ],
        // 'side_door' => [
        //     'name' => 'Side Door',
        //     'ip'   => '172.16.111.90',
        //     'port' => 4370, 'comm_key' => 0, 'timeout' => 5, 'transport' => 'auto',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Supervisor
    |--------------------------------------------------------------------------
    */
    'supervisor' => [
        'restart_delay' => 3, // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage (JSONL backup of every captured event, per device)
    |--------------------------------------------------------------------------
    */
    'storage_dir' => storage_path('app/zkteco'),
];
