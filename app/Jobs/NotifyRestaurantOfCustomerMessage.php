<?php

namespace App\Jobs;

use App\Enums\StaffRole;
use App\Models\OrderMessage;
use App\Models\Staff;
use App\Notifications\NewOrderChatMessagePushNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * Mijoz chatga yozdi — restoran egasiga botda qisqa bildirishnoma (oshxona
 * paneliga olib boruvchi tugma bilan) + oshxona xodimlariga mavjud Web Push.
 * Real-time panel signali (Reverb) alohida (OrderMessageCreated).
 *
 * Spam bo'lmasligi uchun bitta buyurtma bo'yicha DEBOUNCE_SECONDS ichida
 * faqat bitta bildirishnoma. Matn parse_mode'siz; mijoz username'i/ismi yuborilmaydi.
 */
class NotifyRestaurantOfCustomerMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const DEBOUNCE_SECONDS = 120;

    public int $tries = 2;

    public int $backoff = 20;

    public function __construct(public readonly int $messageId) {}

    public function handle(Nutgram $bot): void
    {
        $message = OrderMessage::with('order.restaurant')->find($this->messageId);
        $order = $message?->order;

        if ($message === null || $order === null || ! $message->fromCustomer()) {
            return;
        }

        $debounceKey = "order-chat:notified:{$order->id}";
        if (Cache::has($debounceKey)) {
            return;
        }

        $restaurant = $order->restaurant;
        $excerpt = mb_strimwidth($message->body, 0, 120, '…');

        $chatIds = Staff::query()
            ->assignedTo($order->restaurant_id)
            ->where('is_active', true)
            ->where('role', StaffRole::RestaurantOwner->value)
            ->whereNotNull('telegram_chat_id')
            ->pluck('telegram_chat_id')
            ->push($restaurant->notify_chat_id)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique();

        $text = __('messages.order_chat.owner_notify', [
            'restaurant' => $restaurant->name,
            'n' => $order->order_number,
            'excerpt' => $excerpt,
        ], 'uz');

        $markup = $this->panelButton();

        foreach ($chatIds as $chatId) {
            try {
                $bot->sendMessage(text: $text, chat_id: $chatId, reply_markup: $markup);
            } catch (TelegramException $e) {
                Log::info('[order-chat] egasiga yuborilmadi', [
                    'order' => $order->order_number,
                    'chat_id' => $chatId,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        $pushRecipients = Staff::query()
            ->kitchenRecipients($order->restaurant_id)
            ->whereHas('pushSubscriptions')
            ->get();

        if ($pushRecipients->isNotEmpty()) {
            Notification::send($pushRecipients, new NewOrderChatMessagePushNotification(
                $order->order_number,
                $excerpt,
                $restaurant->name,
            ));
        }

        Cache::put($debounceKey, 1, self::DEBOUNCE_SECONDS);
    }

    /** Telegram faqat https URL tugmasini qabul qiladi — lokal (http) muhitda tugmasiz. */
    private function panelButton(): ?InlineKeyboardMarkup
    {
        $url = url('/kitchen');

        if (! str_starts_with($url, 'https://')) {
            return null;
        }

        return InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(__('messages.order_chat.open_panel', [], 'uz'), url: $url),
        );
    }
}
