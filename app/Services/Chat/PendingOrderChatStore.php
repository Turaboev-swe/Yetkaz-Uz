<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\Cache;

/**
 * Chat holati (Redis/Cache):
 *  - mijozning faol chati: qaysi buyurtmaga yozayotgani (telegram_id bo'yicha, qisqa TTL);
 *  - "raqam ishlamayapti" kutilishi: buyurtma bo'yicha — keyingi to'g'ri raqam
 *    aloqa raqamini yangilaydi.
 * PendingRatingStore / PendingFeedbackStore bilan bir xil naqsh.
 */
class PendingOrderChatStore
{
    private const CHAT_TTL = 60 * 30;

    private const PHONE_TTL = 60 * 60 * 24;

    public function remember(int $telegramUserId, int $orderId): void
    {
        Cache::put("order-chat:pending:{$telegramUserId}", $orderId, self::CHAT_TTL);
    }

    public function pending(int $telegramUserId): ?int
    {
        $value = Cache::get("order-chat:pending:{$telegramUserId}");

        return $value !== null ? (int) $value : null;
    }

    public function forget(int $telegramUserId): void
    {
        Cache::forget("order-chat:pending:{$telegramUserId}");
    }

    public function awaitPhone(int $orderId): void
    {
        Cache::put("order-chat:await-phone:{$orderId}", 1, self::PHONE_TTL);
    }

    public function isAwaitingPhone(int $orderId): bool
    {
        return Cache::has("order-chat:await-phone:{$orderId}");
    }

    public function clearAwaitingPhone(int $orderId): void
    {
        Cache::forget("order-chat:await-phone:{$orderId}");
    }
}
