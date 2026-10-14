<?php

namespace App\Telegram\Handlers;

use App\Models\Order;
use App\Services\Chat\OrderChatException;
use App\Services\Chat\OrderChatService;
use App\Services\Chat\PendingOrderChatStore;
use SergiX44\Nutgram\Nutgram;

/**
 * Mijozning matni faol buyurtma chatiga tegishlimi? MenuHandler dispetcheri chaqiradi.
 * Tegishli bo'lsa xabar saqlanadi/oshxonaga ketadi va `true` qaytadi (dispetcher to'xtaydi).
 */
class ChatMessageHandler
{
    public function __construct(
        private readonly OrderChatService $chat,
        private readonly PendingOrderChatStore $pending,
    ) {}

    public function __invoke(Nutgram $bot, string $text): bool
    {
        $telegramId = (int) $bot->userId();
        $orderId = $this->pending->pending($telegramId);

        if ($orderId === null) {
            return false;
        }

        $user = $bot->get('user');
        $order = Order::withoutGlobalScopes()->find($orderId);

        if ($user === null || $order === null || $order->user_id !== $user->id) {
            $this->pending->forget($telegramId);

            return false;
        }

        try {
            $result = $this->chat->sendFromCustomer($order, $user, $text);
        } catch (OrderChatException $e) {
            if (in_array($e->reason, ['closed', 'forbidden'], true)) {
                $this->pending->forget($telegramId);
            }
            $bot->sendMessage($e->getMessage());

            return true;
        }

        if ($result->updatedPhone !== null) {
            $bot->sendMessage(__('messages.order_chat.phone_updated', ['phone' => $result->updatedPhone]));
        } elseif ($result->phoneRejected) {
            $bot->sendMessage(__('messages.order_chat.sent').' '.__('messages.contact_phone.invalid'));
        } else {
            $bot->sendMessage(__('messages.order_chat.sent'));
        }

        return true;
    }
}
