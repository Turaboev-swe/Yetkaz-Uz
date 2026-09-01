<?php

namespace App\Services\Dispatch;

use App\Enums\PosType;
use App\Models\Order;

/**
 * Buyurtmani restoran `pos_type` iga qarab tegishli drayverga uzatadi.
 */
class OrderDispatcher
{
    public function __construct(
        private readonly EscPosDriver $escPos,
        private readonly ManualDriver $manual,
    ) {}

    public function dispatch(Order $order): DispatchResult
    {
        $driver = match ($order->restaurant->pos_type) {
            PosType::EscPos => $this->escPos,
            // jowi/poster/iiko integratsiyasi yo'q — hozircha manual kabi
            default => $this->manual,
        };

        return $driver->dispatch($order);
    }
}
