<?php

namespace App\Telegram\Support;

use Illuminate\Support\Facades\Cache;

/**
 * "Oxirgi restoran" — QR / chuqur havola orqali kelgan foydalanuvchi qaysi
 * restoran menyusiga qarayotgani. Telefon so'rovidan keyin uni o'sha menyuga
 * qaytarish uchun eslab qolinadi.
 */
final class LastRestaurantStore
{
    private const TTL = 86400; // 1 kun

    public function remember(int $telegramUserId, int $restaurantId): void
    {
        Cache::put($this->key($telegramUserId), $restaurantId, self::TTL);
    }

    public function get(int $telegramUserId): ?int
    {
        $id = Cache::get($this->key($telegramUserId));

        return $id !== null ? (int) $id : null;
    }

    public function forget(int $telegramUserId): void
    {
        Cache::forget($this->key($telegramUserId));
    }

    private function key(int $telegramUserId): string
    {
        return "guest:last_restaurant:{$telegramUserId}";
    }
}
