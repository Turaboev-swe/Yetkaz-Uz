<?php

namespace App\Support;

/**
 * Daqiqani odam o'qiydigan davomiylikka o'giradi (hisobotlar uchun).
 */
final class Duration
{
    public static function human(int|float|null $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        $minutes = (float) $minutes;

        if ($minutes < 1) {
            return '<1 daq';
        }
        if ($minutes < 60) {
            return round($minutes, 1).' daq';
        }
        if ($minutes < 1440) {
            $h = intdiv((int) round($minutes), 60);
            $m = (int) round($minutes) % 60;

            return $m > 0 ? "{$h} soat {$m} daq" : "{$h} soat";
        }

        return round($minutes / 1440, 1).' kun';
    }
}
