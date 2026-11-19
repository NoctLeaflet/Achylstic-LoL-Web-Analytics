<?php

namespace App\Support;

/**
 * Small display helpers for the Blade views.
 */
final class Format
{
    /** 1805 seconds -> "30:05" */
    public static function duration(int $seconds): string
    {
        return intdiv($seconds, 60).':'.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
    }

    /** Fixed decimals without a thousands separator: 6.4, 900.0 */
    public static function number(float|int $value, int $decimals = 1): string
    {
        return number_format($value, $decimals, '.', '');
    }
}
