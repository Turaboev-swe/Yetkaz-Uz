<?php

namespace App\Services\Delivery;

/**
 * Yetkazish narxi natijasi: ilovadagi narx (tiyinda, butun so'm) va yetkazish
 * haqi taksiga alohida to'lanadimi (Royal Taxi rejimida bepul zonadan tashqarida).
 */
final class DeliveryQuote
{
    public function __construct(
        public readonly int $fee,
        public readonly bool $paidToTaxi = false,
    ) {}
}
