<?php

namespace App\Jobs;

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
 * Buyurtma bekor qilindi — mijozga sabab bilan xabar (NotifyCustomerOfStatusChange
 * dagi oddiy "bekor qilindi" o'rniga; sabab ko'rsatiladi).
 */
class NotifyCustomerOfCancellation implements ShouldQueue
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

        app()->setLocale($order->user->language ?: 'uz');

        $text = __('messages.order_notify.cancelled_full', [
            'n' => $order->order_number,
            'reason' => $order->cancellation_reason ?: '—',
        ]);

        try {
            $bot->sendMessage(text: $text, chat_id: $order->user->telegram_id);
        } catch (TelegramException $e) {
            $m = strtolower($e->getMessage());
            if (str_contains($m, 'chat not found') || str_contains($m, 'bot was blocked') || str_contains($m, 'deactivated')) {
                Log::info('[cancel-notify] mijozga yuborilmadi', ['order' => $order->order_number, 'reason' => $e->getMessage()]);

                return;
            }
            throw $e;
        }
    }
}
