<?php

namespace App\Enums;

/**
 * Taom kaloriyasi (AI taxmini) holati. Mijozga faqat Approved ko'rinadi.
 * null (holat yo'q) — hali taxmin qilinmagan.
 */
enum NutritionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Hidden = 'hidden';

    public function label(): string
    {
        return __("messages.nutrition_status.{$this->value}");
    }

    public function emoji(): string
    {
        return match ($this) {
            self::Pending => '⏳',
            self::Approved => '✅',
            self::Hidden => '🚫',
        };
    }
}
