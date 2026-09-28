<?php

namespace App\Enums;

/**
 * Promokod nima uchun qabul qilinmadi — mijozga aniq sabab ko'rsatish uchun
 * (POST /api/promo-codes/validate va buyurtma yaratishда bir xil).
 */
enum PromoCodeError: string
{
    case NotFound = 'not_found';
    case Inactive = 'inactive';
    case NotStarted = 'not_started';
    case Expired = 'expired';
    case WrongRestaurant = 'wrong_restaurant';
    case UsageLimitReached = 'usage_limit_reached';
    case UserLimitReached = 'user_limit_reached';

    public function message(): string
    {
        return __("messages.promo_code_error.{$this->value}");
    }
}
