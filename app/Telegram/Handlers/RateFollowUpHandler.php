<?php

namespace App\Telegram\Handlers;

use App\Models\Order;
use App\Telegram\Conversations\OrderCommentConversation;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;

/**
 * `ratefu:{orderId}:{action}` — baholangandan keyingi tanlov.
 *   c — "Izoh qoldirish": izoh suhbatini boshlaydi
 *   s — "Yo'q, rahmat": tugmalar olib tashlanadi, izohsiz yakunlanadi
 *
 * Har ikkalasida ham xabar tugmalari olib tashlanadi (qayta bosishga qarshi).
 * Faqat buyurtma egasi. Izoh allaqachon yozilgan bo'lsa — qayta so'ralmaydi.
 */
class RateFollowUpHandler
{
    public function __invoke(Nutgram $bot, string $orderId, string $action): void
    {
        $order = Order::withoutGlobalScopes()->with('user')->find((int) $orderId);

        if ($order === null || $order->user?->telegram_id !== $bot->userId() || $order->rating === null) {
            $bot->answerCallbackQuery();

            return;
        }

        $bot->answerCallbackQuery();
        $this->removeButtons($bot, $order);

        if ($action === 'c' && $order->rating_comment === null) {
            OrderCommentConversation::begin($bot, data: [$order->id]);
        }
    }

    /** Xabardagi inline tugmalarni olib tashlaydi (matn o'zgarmaydi). */
    private function removeButtons(Nutgram $bot, Order $order): void
    {
        try {
            $bot->editMessageReplyMarkup(reply_markup: null);
        } catch (TelegramException $e) {
            Log::info('[rating] editMessageReplyMarkup o\'tkazib yuborildi', [
                'order' => $order->order_number,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
