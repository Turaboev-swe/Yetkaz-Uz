<?php

namespace App\Telegram\Handlers;

use App\Models\Order;
use App\Telegram\Handlers\Concerns\ResolvesKitchenStaff;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * `kcourier:{orderId}:{expected}` — "Yo'lga chiqdi" bosildi (yetkazish).
 * Kuryer turini so'raydi: o'z xodimi yoki Royal Taxi. `kcancel:`/`kcreason:`
 * bilan bir xil bosqichli naqsh (avval turi, keyin tafsilot).
 */
class KitchenCourierTypeHandler
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

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make($t('courier_own'), callback_data: "kcourierown:{$order->id}:{$expected}"))
            ->addRow(InlineKeyboardButton::make($t('courier_taxi'), callback_data: "kcouriertaxi:{$order->id}:{$expected}"));

        $bot->sendMessage(text: $t('courier_ask_type').' '.$order->order_number, reply_markup: $keyboard);
    }
}
