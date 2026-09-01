<?php

namespace App\Broadcasting;

use App\Models\Staff;

/**
 * `kitchen.{restaurantId}` kanaliga kirish — faqat o'sha restoran oshxona
 * xodimi yoki egasi.
 */
class KitchenChannel
{
    public function join(Staff $staff, int $restaurantId): bool
    {
        return $staff->canManageKitchen()
            && (int) $staff->restaurant_id === $restaurantId;
    }
}
