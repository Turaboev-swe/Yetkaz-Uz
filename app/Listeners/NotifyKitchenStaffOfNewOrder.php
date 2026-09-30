<?php

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Models\Order;
use App\Models\Staff;
use App\Telegram\Support\KitchenOrderMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

/**
 * Yangi buyurtma — restorandagi har bir xodimга (telegram_chat_id to'ldirilgan,
 * faol, kitchen_staff yoki restaurant_owner) alohida xabar + "keyingi bosqich"
 * tugmasi. Har biri o'z tugmasini bosishi mumkin; birinchi bosgan g'olib.
 *
 * Xabar YUBORISH — status boshqaruvi KitchenCallbackHandler'da.
 */
class NotifyKitchenStaffOfNewOrder implements ShouldQueue
{
    public int $tries = 2;

    public int $backoff = 20;

    public function handle(OrderPlaced $event): void
    {
        $order = Order::withoutGlobalScopes()->with(['user', 'restaurant'])->find($event->orderId);

        if ($order === null) {
            return;
        }

        // Pivot orqali — bir nechta restoranga biriktirilgan xodim ham shu yerda.
        $recipients = Staff::query()
            ->kitchenRecipients($order->restaurant_id)
            ->whereNotNull('telegram_chat_id')
            ->pluck('telegram_chat_id')
            // Bir chat — bir xabar: bir odam ikki xodim yozuvida (masalan egasi
            // ham oshxona xodimi sifatida) bo'lsa ham ikki marta kelmasin.
            ->unique();

        if ($recipients->isEmpty()) {
            return;
        }

        $bot = app(Nutgram::class);
        $message = app(KitchenOrderMessage::class);
        $text = $message->text($order);
        $keyboard = $message->keyboard($order);

        foreach ($recipients as $chatId) {
            try {
                $bot->sendMessage(
                    text: $text,
                    chat_id: (int) $chatId,
                    parse_mode: ParseMode::HTML,
                    reply_markup: $keyboard,
                );
            } catch (TelegramException $e) {
                // Bitta xodim botni bloklagan / ishga tushirmagan — qolganlarга yuboriladi.
                Log::info('[kitchen-notify] xodimga yuborilmadi', [
                    'order' => $order->order_number,
                    'chat_id' => $chatId,
                    'reason' => $e->getMessage(),
                ]);
            }
        }
    }
}
