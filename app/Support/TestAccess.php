<?php

namespace App\Support;

use App\Models\User;

/**
 * Test restoran (restaurants.is_test) kimga ko'rinadi.
 *
 * Faqat TEST_TELEGRAM_IDS (config('telegram.test_telegram_ids')) dagi Telegram
 * hisoblar. Ro'yxat bo'sh bo'lsa — hech kim (xavfsiz standart holat).
 */
class TestAccess
{
    public static function isTester(?User $user): bool
    {
        if ($user === null || $user->telegram_id === null) {
            return false;
        }

        return in_array((string) $user->telegram_id, config('telegram.test_telegram_ids', []), true);
    }
}
