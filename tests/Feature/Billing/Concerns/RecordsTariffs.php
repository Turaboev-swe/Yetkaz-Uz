<?php

namespace Tests\Feature\Billing\Concerns;

use App\Enums\BillingPlan;
use App\Models\Restaurant;
use App\Models\RestaurantBillingPlan;
use App\Services\Billing\TariffService;

/**
 * Davr tarifi TARIX jadvalidan olinadi — testlarda restoran tarifi tarixga ham yoziladi
 * (aks holda hisob-kitob uchun tarifsiz hisoblanadi).
 */
trait RecordsTariffs
{
    /** Restoranning joriy billing_* ustunlaridan tarix yozuvi yaratadi (tarif belgilangan bo'lsa). */
    protected function recordTariff(Restaurant $restaurant, string $from = '2026-11-01'): Restaurant
    {
        if ($restaurant->billing_plan !== null) {
            $this->setTariff($restaurant, $restaurant->billing_plan, (int) $restaurant->commission_rate_bp, (int) $restaurant->monthly_fee, $from);
        }

        return $restaurant;
    }

    /** Tarix yozuvini qo'shadi/yangilaydi va restoranning joriy (JORIY oy uchun amaldagi) ustunlarini moslaydi. */
    protected function setTariff(Restaurant $restaurant, ?BillingPlan $plan, int $rateBp = 500, int $fee = 10_000_000, string $from = '2026-11-01'): void
    {
        RestaurantBillingPlan::query()->updateOrCreate(
            ['restaurant_id' => $restaurant->id, 'effective_from' => $from],
            ['plan' => $plan, 'rate_bp' => $rateBp, 'monthly_fee' => $fee],
        );

        app(TariffService::class)->syncCurrentTariff($restaurant);
    }
}
