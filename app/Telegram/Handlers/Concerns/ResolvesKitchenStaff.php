<?php

namespace App\Telegram\Handlers\Concerns;

use App\Models\Staff;
use SergiX44\Nutgram\Nutgram;

/**
 * Oshxona bot callback/conversation handlerlari uchun: xodimni `telegram_chat_id`
 * bo'yicha topadi va oshxonani boshqarish huquqini tekshiradi.
 */
trait ResolvesKitchenStaff
{
    protected function kitchenStaff(Nutgram $bot): ?Staff
    {
        $staff = Staff::query()
            ->where('telegram_chat_id', $bot->userId())
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        return $staff instanceof Staff && $staff->canManageKitchen() ? $staff : null;
    }
}
