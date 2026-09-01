<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Chek 3 urinishдан keyin ham chiqmadi — oshxona paneliga ogohlantirish.
 */
class OrderDispatchFailed implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $orderId,
        public readonly int $restaurantId,
        public readonly string $orderNumber,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("kitchen.{$this->restaurantId}")];
    }

    public function broadcastAs(): string
    {
        return 'order.dispatch_failed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->orderId,
            'order_number' => $this->orderNumber,
            'message' => 'Chek chiqmadi — qo\'lda tekshiring',
        ];
    }
}
