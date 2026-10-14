<?php

namespace App\Jobs;

use App\Models\OrderMessage;
use App\Models\User;
use App\Telegram\Support\Keyboards;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;

/**
 * Restoran xodimining chat javobi — mijozga bot orqali:
 *   "{Restoran} · Buyurtma #{raqam}: ..." + "Javob yozish" tugmasi (shu buyurtmaga bog'langan).
 *
 * Matn parse_mode'siz yuboriladi (xodim yozgan matn hech qachon HTML sifatida
 * talqin qilinmaydi). Xodim ismi yuborilmaydi — faqat restoran nomi. Til: mijoz
 * tili, locale har __() ga alohida beriladi (global locale oqib ketmasligi uchun).
 */
class SendOrderChatMessageToCustomer implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public readonly int $messageId) {}

    public function handle(Nutgram $bot): void
    {
        $message = OrderMessage::with('order.restaurant', 'order.user')->find($this->messageId);
        $order = $message?->order;

        if ($message === null || $order === null || $message->fromCustomer() || blank($order->user?->telegram_id)) {
            return;
        }

        $locale = in_array($order->user->language, User::LANGUAGES, true) ? $order->user->language : 'uz';

        $text = __('messages.order_chat.from_restaurant', [
            'restaurant' => $order->restaurant->name,
            'n' => $order->order_number,
            'body' => $message->body,
        ], $locale);

        try {
            $bot->sendMessage(
                text: $text,
                chat_id: $order->user->telegram_id,
                reply_markup: Keyboards::orderChat($order->id, $locale, reply: true),
            );
        } catch (TelegramException $e) {
            $m = strtolower($e->getMessage());
            if (str_contains($m, 'chat not found') || str_contains($m, 'bot was blocked') || str_contains($m, 'deactivated')) {
                Log::info('[order-chat] mijozga yuborilmadi', ['order' => $order->order_number, 'reason' => $e->getMessage()]);

                return;
            }
            throw $e;
        }
    }
}
