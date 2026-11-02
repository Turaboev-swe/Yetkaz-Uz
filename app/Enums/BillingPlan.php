<?php

namespace App\Enums;

/** Restoran obuna tarifi: oylik yetkazilgan taom summasidan foiz yoki qat'iy oylik to'lov. */
enum BillingPlan: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Percent => 'Foiz',
            self::Fixed => 'Qat‘iy oylik',
        };
    }
}
