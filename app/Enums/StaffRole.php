<?php

namespace App\Enums;

enum StaffRole: string
{
    case PlatformAdmin = 'platform_admin';
    case RestaurantOwner = 'restaurant_owner';
    case KitchenStaff = 'kitchen_staff';

    public function label(): string
    {
        return __("messages.staff_role.{$this->value}");
    }

    /** Restoran bilan bog'langan bo'lishi shartmi. */
    public function requiresRestaurant(): bool
    {
        return $this !== self::PlatformAdmin;
    }
}
