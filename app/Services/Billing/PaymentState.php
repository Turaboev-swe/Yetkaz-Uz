<?php

namespace App\Services\Billing;

use App\Enums\PaymentBadge;
use Carbon\CarbonImmutable;

/** Restoranning hozir to'lanishi kerak hisobi: badge, davr (percent uchun o'tgan oy), hisob id va qolgan qarz. */
final class PaymentState
{
    public function __construct(
        public readonly PaymentBadge $badge,
        public readonly ?CarbonImmutable $period = null,
        public readonly ?int $invoiceId = null,
        /** Qolgan qarz, tiyin (kechirilgan hisobda 0). */
        public readonly int $debt = 0,
    ) {}
}
