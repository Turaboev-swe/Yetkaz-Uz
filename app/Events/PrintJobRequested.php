<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Chek chiqarish so'rovi — oshxona kompyuteridagi print agentga.
 *
 * ShouldBroadcastNow: DispatchOrderJob ichida SINXRON yuboriladi — Reverb
 * ishlamasa shu yerда xato bo'ladi va job qayta urinadi.
 */
class PrintJobRequested implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $orderId,
        public readonly int $restaurantId,
        public readonly string $orderNumber,
        public readonly string $escposBase64,
        public readonly string $text,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("restaurant.{$this->restaurantId}.print")];
    }

    public function broadcastAs(): string
    {
        return 'print.requested';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->orderId,
            'order_number' => $this->orderNumber,
            'escpos' => $this->escposBase64,
            'text' => $this->text,
        ];
    }
}
