<?php

namespace App\Services\Billing;

use App\Enums\BillingPlan;

/**
 * Bitta restoranning bitta oy uchun hisoblangan obuna natijasi (pullar tiyinda).
 * `ordersTotal` — komissiya bazasi: taom summasi − restoran ulushidagi chegirma (yetkazilganlar).
 */
final class BillingResult
{
    public function __construct(
        public readonly ?BillingPlan $plan,
        public readonly int $rateBp,
        public readonly int $fixedFee,
        public readonly int $ordersCount,
        public readonly int $ordersTotal,
        public readonly int $amountDue,
        /** Tarif belgilangan, test restoran emas va davr hisob-kitob boshlangandan keyin. */
        public readonly bool $billable,
    ) {}
}
