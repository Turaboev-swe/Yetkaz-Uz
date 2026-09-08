<?php

namespace App\Services\Ordering;

use App\Models\Order;
use Illuminate\Support\Facades\Cache;

/**
 * "Kutilayotgan reyting" holati — foydalanuvchi buyurtmasi bo'yicha baho so'rovi
 * yuborilgan, lekin javob (yulduzcha yoki izoh) hali kelmagan.
 *
 * Holat foydalanuvchi bo'yicha (telegram_id) saqlanadi va faqat ENG OXIRGI
 * so'ralgan buyurtmani ko'rsatadi. 24 soatdan keyin eskiradi — undan keyin oddiy
 * matnli xabar izoh sifatida qabul qilinmaydi. Foydalanuvchi asosiy menyu
 * buyrug'ini bosса ham holat tugaydi (`forget`).
 *
 * Yulduzcha bosishдан keyin ham holat qoladi — foydalanuvchi keyin izoh yozishi
 * mumkin (aniq "yakunlash" tugmasi yo'q).
 */
class PendingRatingStore
{
    /** 24 soat. */
    private const TTL = 60 * 60 * 24;

    public function remember(int $telegramUserId, int $orderId): void
    {
        Cache::put($this->key($telegramUserId), $orderId, self::TTL);
    }

    public function pendingOrderId(int $telegramUserId): ?int
    {
        $value = Cache::get($this->key($telegramUserId));

        return $value === null ? null : (int) $value;
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

        $orderId = $this->pendingOrderId($telegramUserId);
        if ($orderId === null) {
            return null;
        }

        $order = Order::withoutGlobalScopes()->with('user')->find($orderId);
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
