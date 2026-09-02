<?php

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Hisobot sana oralig'i. Chegaralar ilova vaqt zonasida (Asia/Tashkent) beriladi,
 * so'rovlar esa UTC ustunlar bilan ishlaydi — shuning uchun `fromUtc()` / `toUtc()`.
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
        $now = CarbonImmutable::now();

        [$from, $to] = match ($key) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'week' => [$now->startOfWeek(), $now->endOfWeek()],
            'quarter' => [$now->startOfQuarter(), $now->endOfQuarter()],
            'all' => [CarbonImmutable::create(2020, 1, 1)->startOfDay(), $now->endOfDay()],
            default => [$now->startOfMonth(), $now->endOfMonth()], // month
        };

        return new self($from, $to, in_array($key, ['today', 'week', 'month', 'quarter', 'all'], true) ? $key : 'month');
    }

    public static function custom(Carbon|CarbonImmutable|string $from, Carbon|CarbonImmutable|string $to): self
    {
        return new self(
            CarbonImmutable::parse($from)->startOfDay(),
            CarbonImmutable::parse($to)->endOfDay(),
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
