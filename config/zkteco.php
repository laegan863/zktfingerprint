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
        'admin_building' => [
            'name'      => env('ZKT_ADMIN_BUILDING_NAME', 'admin Building'),
            'ip'        => env('ZKT_ADMIN_BUILDING_IP', '172.16.201.2'),
            'port'      => (int) env('ZKT_ADMIN_BUILDING_PORT', 4370),
            'comm_key'  => (int) env('ZKT_ADMIN_BUILDING_KEY', 0),
            'timeout'   => 5,
            'transport' => 'auto',
        ],
        'HR_Enrollment' => [
            'name'      => env('ZKT_HR_ENROLLMENT_NAME', 'HR Enrollment'),
            'ip'        => env('ZKT_HR_ENROLLMENT_IP', '172.16.201.70'),
            'port'      => (int) env('ZKT_HR_ENROLLMENT_PORT', 4370),
            'comm_key'  => (int) env('ZKT_HR_ENROLLMENT_KEY', 0),
            'timeout'   => 5,
            'transport' => 'auto',
        ],
        'la_salle' => [
            'name'      => env('ZKT_LA_SALLE_NAME', 'La Salle'),
            'ip'        => env('ZKT_LA_SALLE_IP', '172.16.201.69'),
            'port'      => (int) env('ZKT_LA_SALLE_PORT', 4370),
            'comm_key'  => (int) env('ZKT_LA_SALLE_KEY', 0),
            'timeout'   => 5,
            'transport' => 'auto',
        ],
        'opd' => [
            'name'      => env('ZKT_OPD_NAME', 'OPD'),
            'ip'        => env('ZKT_OPD_IP', '172.16.201.3'),
            'port'      => (int) env('ZKT_OPD_PORT', 4370),
            'comm_key'  => (int) env('ZKT_OPD_KEY', 0),
            'timeout'   => 5,
            'transport' => 'auto',
        ],
        'wt_ground' => [
            'name'      => env('ZKT_WT_GROUND_NAME', 'wt-ground'),
            'ip'        => env('ZKT_WT_GROUND_IP', '172.16.201.1'),
            'port'      => (int) env('ZKT_WT_GROUND_PORT', 4370),
            'comm_key'  => (int) env('ZKT_WT_GROUND_KEY', 0),
            'timeout'   => 5,
            'transport' => 'auto',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Supervisor
    |--------------------------------------------------------------------------
    */
    'supervisor' => [
        'restart_delay' => 3, // seconds
        'wait_db_seconds' => (int) env('ZKT_WAIT_DB_SECONDS', 120), // 0 to disable, -1 to wait forever
        'db_retry_delay' => (int) env('ZKT_DB_RETRY_DELAY', 3),
        'php_bin' => env('ZKT_PHP_BIN', PHP_BINARY),
        'artisan_path' => env('ZKT_ARTISAN_PATH', base_path('artisan')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage (JSONL backup of every captured event, per device)
    |--------------------------------------------------------------------------
    */
    'storage_dir' => storage_path('app/zkteco'),
];
