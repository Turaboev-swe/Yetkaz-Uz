<?php

namespace App\Services\Chat;

use App\Enums\OrderStatus;
use App\Events\OrderContactPhoneChanged;
use App\Events\OrderMessageCreated;
use App\Jobs\NotifyRestaurantOfCustomerMessage;
use App\Jobs\SendOrderChatMessageToCustomer;
use App\Models\Order;
use App\Models\OrderMessage;
use App\Models\Staff;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Buyurtma doirasidagi mijoz <-> restoran chati (faqat matn).
 *
 * Qoidalar:
 *  - chat buyurtma faol bo'lganda ochiq + yetkazilgan/bekor qilingandan keyin 1 soat;
 *  - xabar <= 500 belgi (kesilmaydi, rad etiladi); boshqaruv/bidi belgilari tozalanadi;
 *  - har buyurtma + yuboruvchi tomoni uchun daqiqasiga 10 xabar;
 *  - mijoz faqat o'z buyurtmasiga, xodim faqat o'z restorani buyurtmasiga yozadi.
 *
 * Maxfiylik: xodim ismi/id va mijoz username'i hech qayerga chiqmaydi.
 */
class OrderChatService
{
    public const MAX_LENGTH = 500;

    public const GRACE_MINUTES = 60;

    private const RATE_PER_MINUTE = 10;

    public const QUICK_REPLIES = ['accepted', 'clarify_address', 'unreachable'];

    public function __construct(private readonly PendingOrderChatStore $pending) {}

    public function isOpen(Order $order): bool
    {
        if ($order->status->isActive()) {
            return true;
        }

        $closedAt = $order->status === OrderStatus::Cancelled
            ? ($order->cancelled_at ?? $order->updated_at)
            : ($order->delivered_at ?? $order->updated_at);

        return $closedAt !== null && $closedAt->gt(now()->subMinutes(self::GRACE_MINUTES));
    }

    public function sendFromCustomer(Order $order, User $user, string $text): CustomerChatResult
    {
        if ($order->user_id !== $user->id) {
            throw new OrderChatException('forbidden');
        }

        $body = $this->prepare($order, $text, 'customer');

        $message = OrderMessage::create([
            'order_id' => $order->id,
            'sender_type' => OrderMessage::SENDER_CUSTOMER,
            'sender_id' => $user->id,
            'body' => $body,
            'created_at' => now(),
        ]);

        if (filled($user->telegram_id)) {
            $this->pending->remember((int) $user->telegram_id, $order->id); // suhbat davom etadi
        }

        OrderMessageCreated::dispatch($message->id, $order->id, $order->restaurant_id);
        NotifyRestaurantOfCustomerMessage::dispatch($message->id);

        if (! $this->pending->isAwaitingPhone($order->id)) {
            return new CustomerChatResult($message);
        }

        // "Raqam ishlamayapti" kutilmoqda: to'g'ri raqam kelsa aloqa raqamlari yangilanadi.
        $phone = Phone::normalizeUzbek($body);

        if ($phone === null) {
            return new CustomerChatResult($message, phoneRejected: true);
        }

        DB::transaction(function () use ($order, $user, $phone): void {
            $order->forceFill(['contact_phone' => $phone])->save();
            $user->forceFill(['contact_phone' => $phone])->save();
        });
        $this->pending->clearAwaitingPhone($order->id);

        OrderContactPhoneChanged::dispatch($order->id, $order->restaurant_id);

        return new CustomerChatResult($message, updatedPhone: $phone);
    }

    /**
     * Restoran xodimi yozadi. `$quick` berilsa — tayyor javob (mijoz tilida).
     *
     * @param  string|null  $quick  QUICK_REPLIES dan biri
     */
    public function sendFromRestaurant(Order $order, Staff $staff, ?string $text, ?string $quick = null): OrderMessage
    {
        $this->authorizeStaff($order, $staff);

        if ($quick !== null) {
            abort_unless(in_array($quick, self::QUICK_REPLIES, true), 422);
            $text = (string) __("messages.order_chat.quick.{$quick}", [], $this->locale($order));
        }

        return $this->storeRestaurantMessage($order, $staff, (string) $text);
    }

    /**
     * "Raqam ishlamayapti": mijozga tayyor xabar yuboriladi, mijoz chati ochiladi
     * va keyingi to'g'ri raqam aloqa raqamini yangilaydi.
     */
    public function requestPhone(Order $order, Staff $staff): OrderMessage
    {
        $this->authorizeStaff($order, $staff);

        $message = $this->storeRestaurantMessage(
            $order,
            $staff,
            (string) __('messages.order_chat.phone_unreachable', [], $this->locale($order)),
        );

        $this->pending->awaitPhone($order->id);

        $order->loadMissing('user');
        if (filled($order->user?->telegram_id)) {
            $this->pending->remember((int) $order->user->telegram_id, $order->id);
        }

        return $message;
    }

    /** Restoran tomonidan o'qilmagan mijoz xabarlarini o'qilgan deb belgilaydi. */
    public function markRead(Order $order): int
    {
        return OrderMessage::query()
            ->where('order_id', $order->id)
            ->where('sender_type', OrderMessage::SENDER_CUSTOMER)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function unreadCount(Order $order): int
    {
        return OrderMessage::query()
            ->where('order_id', $order->id)
            ->where('sender_type', OrderMessage::SENDER_CUSTOMER)
            ->whereNull('read_at')
            ->count();
    }

    public function isAwaitingPhone(Order $order): bool
    {
        return $this->pending->isAwaitingPhone($order->id);
    }

    /** Tozalangan matn: boshqaruv va bidi-yo'naltiruvchi belgilarsiz, ortiqcha bo'sh qatorlarsiz. */
    public static function clean(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $text) ?? '';
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? '';

        return trim($text);
    }

    private function storeRestaurantMessage(Order $order, Staff $staff, string $text): OrderMessage
    {
        $body = $this->prepare($order, $text, 'restaurant');

        $message = OrderMessage::create([
            'order_id' => $order->id,
            'sender_type' => OrderMessage::SENDER_RESTAURANT,
            'sender_id' => $staff->id,
            'body' => $body,
            'created_at' => now(),
        ]);

        OrderMessageCreated::dispatch($message->id, $order->id, $order->restaurant_id);
        SendOrderChatMessageToCustomer::dispatch($message->id);

        return $message;
    }

    private function authorizeStaff(Order $order, Staff $staff): void
    {
        if (! $staff->canManageRestaurant($order->restaurant_id)) {
            throw new OrderChatException('forbidden');
        }
    }

    /** Ochiqlik, tozalash, uzunlik va tezlik tekshiruvi. */
    private function prepare(Order $order, string $text, string $side): string
    {
        if (! $this->isOpen($order)) {
            throw new OrderChatException('closed');
        }

        $body = self::clean($text);

        if ($body === '') {
            throw new OrderChatException('empty');
        }

        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw new OrderChatException('too_long');
        }

        $key = "order-chat:{$order->id}:{$side}";

        if (RateLimiter::tooManyAttempts($key, self::RATE_PER_MINUTE)) {
            throw new OrderChatException('throttled');
        }

        RateLimiter::hit($key, 60);

        return $body;
    }

    private function locale(Order $order): string
    {
        $order->loadMissing('user');

        return in_array($order->user?->language, User::LANGUAGES, true) ? $order->user->language : 'uz';
    }
}
