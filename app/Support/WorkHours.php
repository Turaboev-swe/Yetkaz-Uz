<?php

namespace App\Support;

use DateTimeInterface;

/**
 * Restoran ish vaqti jadvali.
 *
 * Format (jsonb): {"mon": [["09:00","23:00"]], "tue": [["09:00","14:00"],["17:00","23:00"]], ...}
 * - kun kaliti: mon..sun
 * - har interval: [boshlanish, tugash] "HH:MM"
 * - tunги interval ("18:00"-"02:00") qo'llab-quvvatlanadi
 */
final class WorkHours
{
    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** @param array<string, array<int, array{0:string,1:string}>> $schedule */
    public function __construct(private readonly array $schedule) {}

    /** @param array<string, mixed>|null $schedule */
    public static function from(?array $schedule): self
    {
        return new self(is_array($schedule) ? $schedule : []);
    }

    public function isOpenAt(DateTimeInterface $at): bool
    {
        $day = self::DAYS[(int) $at->format('N') - 1];
        $now = $at->format('H:i');

        foreach ($this->schedule[$day] ?? [] as $interval) {
            if (! is_array($interval) || count($interval) !== 2) {
                continue;
            }

            [$start, $end] = $interval;

            $inside = $start <= $end
                ? ($now >= $start && $now < $end)          // oddiy: 09:00-23:00
                : ($now >= $start || $now < $end);          // tunги: 18:00-02:00

            if ($inside) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->schedule === [];
    }
}
