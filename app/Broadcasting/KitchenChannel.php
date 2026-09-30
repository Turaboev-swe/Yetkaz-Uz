<?php

namespace App\Broadcasting;

use App\Models\Staff;

/**
 * `kitchen.{restaurantId}` kanaliga kirish — shu restoranga biriktirilgan
 * oshxona xodimi yoki egasi. Bir nechta restoranli xodim har biriga obuna
 * bo'ladi.
 */
class KitchenChannel
{
    public function join(Staff $staff, int $restaurantId): bool
    {
        return $staff->canManageRestaurant($restaurantId);
    }
}
