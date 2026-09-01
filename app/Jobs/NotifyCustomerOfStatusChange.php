<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;

/**
 * Buyurtma statusi o'zgardi — mijozga bot orqali xabar.
 */
class NotifyCustomerOfStatusChange implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public readonly int $orderId) {}

    public function handle(Nutgram $bot): void
    {
        $order = Order::withoutGlobalScopes()->with('user')->find($this->orderId);

        if ($order === null || blank($order->user?->telegram_id)) {
            return;
        }

        // Xabar mijozning tilida (uz | ru).
        app()->setLocale($order->user->language ?: 'uz');

        $text = $this->text($order);
        if ($text === null) {
            return; // bu status uchun xabar yo'q (masalan New)
        }

        try {
            $bot->sendMessage(text: $text, chat_id: $order->user->telegram_id);
        } catch (TelegramException $e) {
            $m = strtolower($e->getMessage());
            if (str_contains($m, 'chat not found') || str_contains($m, 'bot was blocked') || str_contains($m, 'deactivated')) {
                Log::info('[status-notify] mijozga yuborilmadi', ['order' => $order->order_number, 'reason' => $e->getMessage()]);

                return;
            }
            throw $e;
        }
    }

    private function text(Order $order): ?string
    {
        $num = $order->order_number;
        $pickup = $order->delivery_type->isPickup();

        return match ($order->status) {
            OrderStatus::Accepted => __('messages.order_notify.accepted', ['n' => $num]),
            OrderStatus::Preparing => __('messages.order_notify.preparing', ['n' => $num]),
            OrderStatus::OnTheWay => __('messages.order_notify.on_the_way', ['n' => $num]),
            OrderStatus::Delivered => $pickup
                ? __('messages.order_notify.picked_up', ['n' => $num])
                : __('messages.order_notify.delivered', ['n' => $num]),
            OrderStatus::Cancelled => __('messages.order_notify.cancelled', ['n' => $num]),
            default => null,
        };
    }
}
