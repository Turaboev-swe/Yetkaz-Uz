<?php

namespace App\Support;

/**
 * Pul tiyinда saqlanadi (integer), ko'rsatishda so'mга o'giriladi.
 */
final class Money
{
    /** Tiyin → "1 234 567 so'm". */
    public static function soms(int|float|null $tiyin): string
    {
        return self::amount($tiyin)." so'm";
    }

    /** Tiyin → "1 234 567" (birliksiz). */
    public static function amount(int|float|null $tiyin): string
    {
        return number_format(self::toSoms($tiyin), 0, '.', ' ');
    }

    /** Tiyin → butun so'm (pastga yaxlitlash). */
    public static function toSoms(int|float|null $tiyin): int
    {
        return intdiv((int) round((float) ($tiyin ?? 0)), 100);
    }
}
