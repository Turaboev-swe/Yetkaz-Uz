<?php

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Hisobot sana oralig'i. Chegaralar `config('app.display_timezone')`
 * (Asia/Tashkent) bo'yicha quriladi — "Bugun" aynan Toshkent kalendar kuni
 * (00:00-23:59). Baza ustunlari UTC bo'lgani uchun so'rovda `fromUtc()` /
 * `toUtc()` ishlatiladi — bu METODLAR haqiqiy konvertatsiya qiladi (`->from`/
 * `->to` allaqachon Toshkent zonasida, `APP_TIMEZONE=UTC`ga bog'liq emas).
 */
final class ReportPeriod
{
    private function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $key,
    ) {}

    /** today | week | month | quarter | all */
    public static function preset(string $key): self
    {
        $tz = config('app.display_timezone');
        $now = CarbonImmutable::now($tz);

        [$from, $to] = match ($key) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'week' => [$now->startOfWeek(), $now->endOfWeek()],
            'quarter' => [$now->startOfQuarter(), $now->endOfQuarter()],
            'all' => [CarbonImmutable::create(2020, 1, 1, 0, 0, 0, $tz)->startOfDay(), $now->endOfDay()],
            default => [$now->startOfMonth(), $now->endOfMonth()], // month
        };

        return new self($from, $to, in_array($key, ['today', 'week', 'month', 'quarter', 'all'], true) ? $key : 'month');
    }

    /**
     * `$from`/`$to` — Toshkent kalendar sanasi (masalan admin DatePicker'idan
     * "2026-09-21"). Aniq shu zonada tahlil qilinishi shart, aks holda
     * `startOfDay()`/`endOfDay()` noto'g'ri kun chegarasini beradi (REPORT-1).
     */
    public static function custom(Carbon|CarbonImmutable|string $from, Carbon|CarbonImmutable|string $to): self
    {
        $tz = config('app.display_timezone');

        return new self(
            CarbonImmutable::parse($from, $tz)->startOfDay(),
            CarbonImmutable::parse($to, $tz)->endOfDay(),
            'custom',
        );
    }

    public function fromUtc(): CarbonImmutable
    {
        return $this->from->utc();
    }

    public function toUtc(): CarbonImmutable
    {
        return $this->to->utc();
    }

    /** Kunlar soni (grafik uchun teng oraliq). */
    public function days(): int
    {
        return (int) $this->from->startOfDay()->diffInDays($this->to->startOfDay()) + 1;
    }

    public function label(): string
    {
        return match ($this->key) {
            'today' => 'Bugun',
            'week' => 'Shu hafta',
            'quarter' => 'Shu chorak',
            'all' => 'Butun davr',
            'custom' => $this->from->format('d.m.Y').' – '.$this->to->format('d.m.Y'),
            default => 'Shu oy',
        };
    }
}
