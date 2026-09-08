<?php

namespace App\Telegram\Handlers;

use App\Models\Order;
use App\Services\Ordering\OrderStatusService;
use App\Telegram\Handlers\Concerns\ResolvesKitchenStaff;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * `kcancel:{orderId}:{expected}` — oshxona xodimi "❌ Bekor qilish" tugmasini bosdi.
 * Sabab tanlash menyusini ochadi (3 variant). Faqat accepted/preparing holatida.
 */
class KitchenCancelHandler
{
    use ResolvesKitchenStaff;

    public function __construct(private readonly OrderStatusService $status) {}

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

        if ($order->status->value !== $expected || ! $this->status->canCancel($order)) {
            $bot->answerCallbackQuery(text: $t('cannot_cancel'), show_alert: true);

            return;
        }

        $bot->answerCallbackQuery();

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make($t('reason_out'), callback_data: "kcreason:{$order->id}:out"))
            ->addRow(InlineKeyboardButton::make($t('reason_busy'), callback_data: "kcreason:{$order->id}:busy"))
            ->addRow(InlineKeyboardButton::make($t('reason_other'), callback_data: "kcreason:{$order->id}:other"));

        $bot->sendMessage(
            text: $t('cancel_pick_reason').' '.$order->order_number,
            reply_markup: $keyboard,
        );
    }
}
