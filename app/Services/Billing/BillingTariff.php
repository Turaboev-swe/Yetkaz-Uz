<?php

namespace App\Services\Billing;

use App\Enums\BillingPlan;

/** Restoranning ma'lum davr uchun amalda bo'lgan tarifi (pullar tiyinda). `plan = null` — tarifsiz. */
final class BillingTariff
{
    public function __construct(
        public readonly ?BillingPlan $plan,
        public readonly int $rateBp,
        public readonly int $monthlyFee,
    ) {}

    /** Bir xil shartlarmi (faqat tanlangan tarifga tegishli qiymat solishtiriladi). */
    public function sameAs(?BillingPlan $plan, int $rateBp, int $monthlyFee): bool
    {
        return $this->plan === $plan && match ($plan) {
            BillingPlan::Percent => $this->rateBp === $rateBp,
            BillingPlan::Fixed => $this->monthlyFee === $monthlyFee,
            null => true,
        };
    }
}
