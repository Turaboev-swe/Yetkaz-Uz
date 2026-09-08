<?php

namespace App\Telegram\Handlers;

use App\Models\Order;
use App\Telegram\Support\RatingMessage;
use Illuminate\Support\Facades\DB;
use SergiX44\Nutgram\Nutgram;

/**
 * `rate:{orderId}:{star}` — mijoz yulduzcha bosdi.
 *
 * - faqat buyurtma egasi (from.id == order.user.telegram_id); boshqasi jimgina rad
 * - yulduzcha bir marta: allaqachon baholangan bo'lsa "Siz allaqachon baholagansiz"
 * - saqlangач so'rov xabari joriy holatga yangilanadi (yulduzcha + bor bo'lsa izoh)
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
        RatingMessage::refresh($bot, $order);
    }
}
