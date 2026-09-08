<?php

namespace App\Services\Ordering;

use App\Models\Order;
use Illuminate\Support\Facades\Cache;

/**
 * "Kutilayotgan reyting" holati — foydalanuvchi buyurtmasi bo'yicha baho so'rovi
 * yuborilgan, lekin javob (yulduzcha yoki izoh) hali to'liq emas.
 *
 * Holat foydalanuvchi bo'yicha (telegram_id) saqlanadi va faqat ENG OXIRGI
 * so'ralgan buyurtmani ko'rsatadi. Saqlanadi: buyurtma id + so'rov xabari id
 * (uni joriy holatga yangilash uchun). 24 soatdan keyin eskiradi — undan keyin
 * oddiy matn izoh sifatida qabul qilinmaydi. Asosiy menyu buyrug'i bosilса ham
 * tugaydi (`forget`). Yulduzcha bosishдан keyin ham qoladi — foydalanuvchi
 * keyin izoh yozishi mumkin.
 */
class PendingRatingStore
{
    /** 24 soat. */
    private const TTL = 60 * 60 * 24;

    public function remember(int $telegramUserId, int $orderId, ?int $promptMessageId = null): void
    {
        Cache::put($this->key($telegramUserId), [
            'order_id' => $orderId,
            'message_id' => $promptMessageId,
        ], self::TTL);
    }

    /** @return array{order_id:int, message_id:?int}|null */
    public function pending(int $telegramUserId): ?array
    {
        $value = Cache::get($this->key($telegramUserId));

        if (! is_array($value) || ! isset($value['order_id'])) {
            return null;
        }

        return [
            'order_id' => (int) $value['order_id'],
            'message_id' => isset($value['message_id']) ? (int) $value['message_id'] : null,
        ];
    }

    public function forget(int $telegramUserId): void
    {
        Cache::forget($this->key($telegramUserId));
    }

    /**
     * Matnli xabarni kutilayotgan buyurtmaning izohi sifatida saqlaydi.
     * Holat yo'q / eskirgan bo'lsa yoki buyurtma egasi mos kelmasa — null.
     */
    public function storeComment(int $telegramUserId, string $text): ?Order
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $pending = $this->pending($telegramUserId);
        if ($pending === null) {
            return null;
        }

        $order = Order::withoutGlobalScopes()->with('user')->find($pending['order_id']);
        if ($order === null || $order->user?->telegram_id !== $telegramUserId) {
            return null;
        }

        $order->forceFill([
            'rating_comment' => mb_substr($text, 0, 1000),
            'rated_at' => $order->rated_at ?? now(),
        ])->save();

        return $order;
    }

    private function key(int $telegramUserId): string
    {
        return "rating:pending:{$telegramUserId}";
    }
}
