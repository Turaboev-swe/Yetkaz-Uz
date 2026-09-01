<?php

namespace App\Events;

use App\Http\Resources\KitchenOrderResource;
use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Yangi buyurtma — oshxona paneliga (`/kitchen`) Reverb orqali.
 */
class OrderPlaced implements ShouldBroadcast
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
        return 'order.placed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $order = Order::withoutGlobalScopes()->with(['user', 'restaurant'])->find($this->orderId);

        return $order ? (new KitchenOrderResource($order))->resolve() : [];
    }
}
