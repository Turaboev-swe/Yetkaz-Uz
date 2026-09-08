<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Telegram\Support\RatingMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;

/**
 * Buyurtma yakunlangach (delivered / mijoz oldi) 15 daqiqadan keyin mijozdan
 * baho so'raydi — 1–5 yulduzcha inline tugmalar bilan.
 *
 * Allaqachon baholangan yoki buyurtma yakunlanmagan bo'lsa — jimgina o'tadi.
 */
class RequestOrderRating implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly int $orderId) {}

    public function handle(Nutgram $bot): void
    {
        $order = Order::withoutGlobalScopes()->with('user')->find($this->orderId);

        if ($order === null || $order->rating !== null) {
            return;
        }

        if ($order->status !== OrderStatus::Delivered || blank($order->user?->telegram_id)) {
            return;
        }

        app()->setLocale($order->user->language ?: 'uz');

        try {
            $bot->sendMessage(
                text: RatingMessage::askText($order),
                chat_id: $order->user->telegram_id,
                reply_markup: RatingMessage::askKeyboard($order->id),
            );
        } catch (TelegramException $e) {
            $m = strtolower($e->getMessage());
            if (str_contains($m, 'chat not found') || str_contains($m, 'bot was blocked') || str_contains($m, 'deactivated')) {
                Log::info('[rating] so\'rov yuborilmadi', ['order' => $order->order_number, 'reason' => $e->getMessage()]);

                return;
            }
            throw $e;
        }
    }
}
