<?php

namespace App\Events;

use App\Http\Resources\OrderMessageResource;
use App\Models\OrderMessage;
use App\Services\Chat\OrderChatService;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Buyurtma chatida yangi xabar — oshxona paneliga (Reverb): belgi, ovoz, ro'yxatga qo'shish. */
class OrderMessageCreated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $messageId,
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
        return 'order.message';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $message = OrderMessage::with('order.restaurant')->find($this->messageId);

        if ($message === null) {
            return [];
        }

        return [
            'order_id' => $this->orderId,
            'message' => (new OrderMessageResource($message))->resolve(),
            'unread' => app(OrderChatService::class)->unreadCount($message->order),
        ];
    }
}
