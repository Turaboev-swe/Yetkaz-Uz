<?php

namespace App\Telegram\Handlers\Concerns;

use App\Models\Order;
use App\Models\Staff;
use SergiX44\Nutgram\Nutgram;

/**
 * Oshxona bot callback/conversation handlerlari uchun: `telegram_chat_id`
 * bo'yicha shu buyurtmaning restoranini boshqara oladigan xodimni topadi.
 *
 * Bitta chat bir nechta xodim yozuvida bo'lishi mumkin (masalan har restoran
 * uchun alohida hisob). Shuning uchun "birinchi topilgan" yozuv emas —
 * buyurtma restoraniga biriktirilgan yozuv olinadi.
 */
trait ResolvesKitchenStaff
{
    protected function kitchenStaffFor(Nutgram $bot, ?Order $order): ?Staff
    {
        if ($order === null || $bot->userId() === null) {
            return null;
        }

        $staff = Staff::query()
            ->where('telegram_chat_id', $bot->userId())
            ->kitchenRecipients($order->restaurant_id)
            ->orderBy('id')
            ->first();

        return $staff instanceof Staff && $staff->canManageRestaurant($order->restaurant_id) ? $staff : null;
    }
}
