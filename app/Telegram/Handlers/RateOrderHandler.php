<?php

namespace App\Telegram\Handlers;

use App\Models\Order;
use App\Telegram\Support\RatingMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;

/**
 * `rate:{orderId}:{star}` — mijoz yulduzcha bosdi.
 *
 * - faqat buyurtma egasi (from.id == order.user.telegram_id); boshqasi jimgina rad
 * - yulduzcha bir marta: allaqachon baholangan bo'lsa "Siz allaqachon baholagansiz"
 * - saqlangach xabar "Rahmat!" ga yangilanadi (tugmalarsiz). Izoh keyin ham
 *   alohida matn bilan yozilishi mumkin (PendingRatingStore holati saqlanadi).
 */
class RateOrderHandler
{
    public function __invoke(Nutgram $bot, string $orderId, string $star): void
    {
        $rating = (int) $star;
        if ($rating < 1 || $rating > 5) {
            $bot->answerCallbackQuery();

            return;
        }

        $outcome = DB::transaction(function () use ($orderId, $rating, $bot): string {
            $order = Order::withoutGlobalScopes()->with('user')->lockForUpdate()->find((int) $orderId);

            if ($order === null || $order->user?->telegram_id !== $bot->userId()) {
                return 'forbidden';
            }

            if ($order->rating !== null) {
                return 'already';
            }

            $order->forceFill([
                'rating' => $rating,
                'rated_at' => $order->rated_at ?? now(),
            ])->save();

            return 'saved';
        });

        if ($outcome === 'forbidden') {
            $bot->answerCallbackQuery();

            return;
        }

        if ($outcome === 'already') {
            $bot->answerCallbackQuery(text: __('messages.rating.already'), show_alert: true);

            return;
        }

        $bot->answerCallbackQuery(text: __('messages.rating.saved_toast'));

        $order = Order::withoutGlobalScopes()->find((int) $orderId);

        try {
            $bot->editMessageText(text: RatingMessage::thanksText($order));
        } catch (TelegramException $e) {
            Log::info('[rating] editMessageText o\'tkazib yuborildi', [
                'order' => $order->order_number,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
