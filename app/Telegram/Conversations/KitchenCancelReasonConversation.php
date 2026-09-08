<?php

namespace App\Telegram\Conversations;

use App\Models\Order;
use App\Services\Ordering\OrderStatusService;
use App\Telegram\Handlers\Concerns\ResolvesKitchenStaff;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

/**
 * "Boshqa sabab" tanlanганда — oshxona xodimidan bekor qilish sababini matn
 * bilan so'raydi. Keyingi matnli xabar sabab sifatida qabul qilinadi
 * (holat Redis'да, RegistrationConversation bilan bir xil mexanizm).
 */
class KitchenCancelReasonConversation extends Conversation
{
    use ResolvesKitchenStaff;

    public ?int $orderId = null;

    public function start(Nutgram $bot, ?int $orderId = null): void
    {
        $this->orderId ??= $orderId;

        $bot->sendMessage(__('messages.kitchen_bot.cancel_ask_reason', [], 'uz'));
        $this->next('handleReason');
    }

    public function handleReason(Nutgram $bot): void
    {
        $reason = trim((string) $bot->message()?->text);

        if ($reason === '' || str_starts_with($reason, '/')) {
            $bot->sendMessage(__('messages.kitchen_bot.cancel_ask_reason', [], 'uz'));
            $this->next('handleReason');

            return;
        }

        $staff = $this->kitchenStaff($bot);
        $order = Order::withoutGlobalScopes()->find($this->orderId);

        if ($staff !== null
            && $order !== null
            && $order->restaurant_id === $staff->restaurant_id) {
            try {
                app(OrderStatusService::class)->cancel($order, $reason, "kitchen:{$staff->id}");
                $bot->sendMessage('✅ '.__('messages.kitchen_bot.cancel_done', [], 'uz').' — '.$order->order_number);
            } catch (ValidationException $e) {
                $bot->sendMessage(collect($e->errors())->flatten()->first() ?: __('messages.kitchen_bot.cannot_cancel', [], 'uz'));
            }
        }

        $this->end();
    }
}
