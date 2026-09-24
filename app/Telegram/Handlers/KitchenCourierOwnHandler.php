<?php

namespace App\Telegram\Handlers;

use App\Models\Order;
use App\Models\Staff;
use App\Telegram\Handlers\Concerns\ResolvesKitchenStaff;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * `kcourierown:{orderId}:{expected}` — "🛵 O'z kuryer bilan" tanlandi.
 * Restoran xodimlari ro'yxatini ko'rsatadi (/kitchen'dagi dropdown bilan
 * bir xil ma'lumot) + "Kuryersiz davom etish".
 */
class KitchenCourierOwnHandler
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

        $bot->answerCallbackQuery();

        $couriers = Staff::query()
            ->where('restaurant_id', $staff->restaurant_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($couriers as $courier) {
            $keyboard->addRow(InlineKeyboardButton::make(
                $courier->name,
                callback_data: "kcourierpick:{$order->id}:{$expected}:{$courier->id}",
            ));
        }
        $keyboard->addRow(InlineKeyboardButton::make(
            $t('courier_no_staff'),
            callback_data: "kcourierpick:{$order->id}:{$expected}:0",
        ));

        $bot->sendMessage(text: $t('courier_pick_staff').' '.$order->order_number, reply_markup: $keyboard);
    }
}
