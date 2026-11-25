<?php

namespace App\Support;

/**
 * Small display helpers for the Blade views.
 */
final class Format
{
    /** 1805 seconds -> "30:05" */
    /** 1 => "1st", 2 => "2nd", 11 => "11th". */
    public static function ordinal(int $number): string
    {
        $suffix = in_array($number % 100, [11, 12, 13], true)
            ? 'th'
            : ([1 => 'st', 2 => 'nd', 3 => 'rd'][$number % 10] ?? 'th');

        return $number.$suffix;
    }

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
