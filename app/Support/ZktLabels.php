<?php

namespace App\Support;

class ZktLabels
{
    /** verify mode (how identity was proven) */
    public const VERIFY = [
        0  => 'Password',
        1  => 'Fingerprint',
        3  => 'Card',
        4  => 'Card',
        15 => 'Face',
        20 => 'Palm',
    ];

    /** status code (why they punched) */
    public const STATUS = [
        0 => 'Check In',
        1 => 'Check Out',
        2 => 'Break Out',
        3 => 'Break In',
        4 => 'Overtime In',
        5 => 'Overtime Out',
    ];

    public static function verify(int $code): string
    {
        return self::VERIFY[$code] ?? "Mode {$code}";
    }

    public static function status(int $code): string
    {
        return self::STATUS[$code] ?? "Status {$code}";
    }
}
