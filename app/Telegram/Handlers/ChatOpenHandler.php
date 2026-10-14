<?php

namespace App\Telegram\Handlers;

use App\Models\Order;
use App\Services\Chat\OrderChatException;
use App\Services\Chat\OrderChatService;
use App\Services\Chat\PendingOrderChatStore;
use SergiX44\Nutgram\Nutgram;

/**
 * `chat:{orderId}` — "💬 Oshxonaga yozish" / "✍️ Javob yozish" tugmasi. Faqat buyurtma
 * egasi (from.id == order.user.telegram_id). Keyingi matn xabari shu buyurtmaning
 * chatiga yoziladi (PendingOrderChatStore, qisqa TTL) — MenuHandler qabul qiladi.
 */
class ChatOpenHandler
{
    public function __construct(
        private readonly OrderChatService $chat,
        private readonly PendingOrderChatStore $pending,
    ) {}

    public function __invoke(Nutgram $bot, string $orderId): void
    {
        $order = Order::withoutGlobalScopes()->with('user')->find((int) $orderId);

        if ($order === null || $order->user?->telegram_id !== $bot->userId()) {
            $bot->answerCallbackQuery();

            return;
        }

        if (! $this->chat->isOpen($order)) {
            $bot->answerCallbackQuery(text: OrderChatException::text('closed'), show_alert: true);

            return;
        }

        $this->pending->remember((int) $bot->userId(), $order->id);

        $bot->answerCallbackQuery();
        $bot->sendMessage(__('messages.order_chat.prompt', ['n' => $order->order_number, 'max' => OrderChatService::MAX_LENGTH]));
    }
}
