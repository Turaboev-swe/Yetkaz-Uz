<?php

namespace App\Services\Eta;

/** Hisoblangan ETA: aniq qiymat + mijozga ko'rsatiladigan oraliq. */
final class EtaEstimate
{
    public function __construct(
        public readonly int $minutes,
        public readonly int $low,
        public readonly int $high,
    ) {}

    /**
     * Aniq daqiqadan mijozga ko'rsatiladigan oraliqni yasaydi:
     * minutes-5 … minutes+10, 5 ga yaxlitlangan (past chegara ≥ 5).
     *
     * Yagona manba — EtaEstimator, OrderResource va chek xabari shu yerdan oladi.
     */
    public static function fromMinutes(int $minutes): self
    {
        $round5 = static fn (int $n): int => (int) (round($n / 5) * 5);

        return new self(
            minutes: $minutes,
            low: max(5, $round5($minutes - 5)),
            high: $round5($minutes + 10),
        );
    }

    public function range(): string
    {
        return "{$this->low}–{$this->high}";
    }
}
