<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Mijoz chatda yangi aloqa raqamini yubordi — oshxona kartasidagi raqam yangilanadi. */
class OrderContactPhoneChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $orderId,
        public readonly int $restaurantId,
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("kitchen.{$this->restaurantId}")];
    }

    public function broadcastAs(): string
    {
        return 'order.contact_phone';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $order = Order::withoutGlobalScopes()->with('user')->find($this->orderId);

        return [
            'order_id' => $this->orderId,
            'contact_phone' => $order?->contactPhone(),
            'phones_differ' => (bool) $order?->hasDistinctContactPhone(),
        ];
    }
}
