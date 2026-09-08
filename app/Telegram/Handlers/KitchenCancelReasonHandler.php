<?php

namespace App\Telegram\Handlers;

use App\Models\Order;
use App\Services\Ordering\OrderStatusService;
use App\Telegram\Conversations\KitchenCancelReasonConversation;
use App\Telegram\Handlers\Concerns\ResolvesKitchenStaff;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;

/**
 * `kcreason:{orderId}:{code}` — bekor qilish sababi tanlandi.
 *   out  -> "Taom tugab qoldi"     -> darhol bekor
 *   busy -> "Restoran hozir band"  -> darhol bekor
 *   other -> matn so'raladi (KitchenCancelReasonConversation)
 *
 * `/kitchen` panelи bilan bir xil OrderStatusService::cancel() ni chaqiradi.
 */
class KitchenCancelReasonHandler
{
    use ResolvesKitchenStaff;

    public function __construct(private readonly OrderStatusService $status) {}

    public function __invoke(Nutgram $bot, string $orderId, string $code): void
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

        if ($code === 'other') {
            $bot->answerCallbackQuery();
            KitchenCancelReasonConversation::begin($bot, data: [$order->id]);

            return;
        }

        $reason = match ($code) {
            'out' => $t('reason_out'),
            'busy' => $t('reason_busy'),
            default => '',
        };

        try {
            $this->status->cancel($order, $reason, "kitchen:{$staff->id}");
            $bot->answerCallbackQuery(text: $t('cancel_done'));
            $bot->sendMessage('✅ '.$t('cancel_done').' — '.$order->order_number);
        } catch (ValidationException) {
            $bot->answerCallbackQuery(text: $t('cannot_cancel'), show_alert: true);
        }
    }
}
