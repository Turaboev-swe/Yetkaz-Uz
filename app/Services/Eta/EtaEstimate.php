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

    public function range(): string
    {
        return "{$this->low}–{$this->high}";
    }
}
