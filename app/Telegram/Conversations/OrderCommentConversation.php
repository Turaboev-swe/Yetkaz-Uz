<?php

namespace App\Telegram\Conversations;

use App\Models\Order;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

/**
 * Baholovga izoh yozish. "💬 Izoh qoldirish" tugmasidan boshlanadi.
 *
 * Holat Redis'da saqlanadi (Nutgram conversation cache) — RegistrationConversation
 * bilan bir xil mexanizm. Keyingi matnli xabar `orders.rating_comment` ga yoziladi,
 * so'ng holat tozalanadi (`end()`), keyingi oddiy xabarlar izoh bo'lib qolmaydi.
 */
class OrderCommentConversation extends Conversation
{
    public ?int $orderId = null;

    public function start(Nutgram $bot, ?int $orderId = null): void
    {
        $this->orderId ??= $orderId;

        $bot->sendMessage(__('messages.rating.ask_comment'));
        $this->next('handleComment');
    }

    public function handleComment(Nutgram $bot): void
    {
        $text = trim((string) $bot->message()?->text);

        // Bo'sh yoki buyruq (masalan /start) — izoh emas, qayta so'raymiz.
        if ($text === '' || str_starts_with($text, '/')) {
            $bot->sendMessage(__('messages.rating.ask_comment'));
            $this->next('handleComment');

            return;
        }

        $order = Order::withoutGlobalScopes()->with('user')->find($this->orderId);

        if ($order !== null
            && $order->rating !== null
            && $order->rating_comment === null
            && $order->user?->telegram_id === $bot->userId()) {
            $order->rating_comment = mb_substr($text, 0, 1000);
            $order->save();
        }

        $bot->sendMessage(__('messages.rating.comment_saved'));
        $this->end();
    }
}
