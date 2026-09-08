<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Ordering\PendingRatingStore;
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
 * Buyurtma yakunlangач (delivered / mijoz oldi) mijozdan baho so'raydi — 1–5
 * yulduzchali inline tugmalar bilan. "Yetkazildi" xabari bilan bir vaqtda,
 * kechikishsiz (OrderStatusService::transition).
 *
 * Foydalanuvchi yulduzcha bosishi YOKI shunchaki izoh matnini yozib yuborishi
 * mumkin (PendingRatingStore). Allaqachon javob berilган (`rated_at`) yoki
 * buyurtma yakunlanmagан bo'lsa — jimgina o'tadi.
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

    public function handle(Nutgram $bot, PendingRatingStore $pending): void
    {
        $order = Order::withoutGlobalScopes()->with('user')->find($this->orderId);

        if ($order === null || $order->rated_at !== null) {
            return;
        }

        if ($order->status !== OrderStatus::Delivered || blank($order->user?->telegram_id)) {
            return;
        }

        app()->setLocale($order->user->language ?: 'uz');

        try {
            $message = $bot->sendMessage(
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

        // Endi shu foydalanuvchidan kelgan matn (menyu tugmasi bo'lmasa) shu
        // buyurtmaning izohi sifatida qabul qilinadi — 24 soat ichida.
        $pending->remember($order->user->telegram_id, $order->id, $message?->message_id);
    }
}
