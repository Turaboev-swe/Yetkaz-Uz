<?php

namespace App\Telegram\Handlers;

use App\Models\Order;
use App\Telegram\Conversations\KitchenCourierPhoneConversation;
use App\Telegram\Handlers\Concerns\ResolvesKitchenStaff;
use SergiX44\Nutgram\Nutgram;

/**
 * `kcouriertaxi:{orderId}:{expected}` — "🚕 Royal Taxi orqali" tanlandi.
 * Haydovchi telefon raqamini so'raydi (KitchenCourierPhoneConversation).
 */
class KitchenCourierTaxiHandler
{
    use ResolvesKitchenStaff;

    public function __invoke(Nutgram $bot, string $orderId, string $expected): void
    {
        $t = fn (string $k): string => (string) __("messages.kitchen_bot.$k", [], 'uz');

        $staff = $this->kitchenStaff($bot);
        if ($staff === null) {
            $bot->answerCallbackQuery(text: $t('cb_no_access'), show_alert: true);

            return;
        }

        $order = Order::withoutGlobalScopes()->find((int) $orderId);
        if ($order === null || $order->restaurant_id !== $staff->restaurant_id) {
            $bot->answerCallbackQuery(text: $t('cb_no_access'), show_alert: true);

            return;
        }

        if ($order->status->value !== $expected) {
            $bot->answerCallbackQuery(text: $t('cb_stale'));

            return;
        }

        $bot->answerCallbackQuery();
        KitchenCourierPhoneConversation::begin($bot, data: [$order->id, $expected]);
    }
}
