<?php

namespace App\Policies;

use App\Models\Restaurant;
use App\Models\Staff;

class RestaurantPolicy
{
    public function viewAny(Staff $staff): bool
    {
        return $staff->isPlatformAdmin();
    }

    public function view(Staff $staff, Restaurant $restaurant): bool
    {
        return $staff->isPlatformAdmin()
            || ($staff->isRestaurantOwner() && $restaurant->id === $staff->restaurant_id);
    }

    public function create(Staff $staff): bool
    {
        return $staff->isPlatformAdmin();
    }

    public function update(Staff $staff, Restaurant $restaurant): bool
    {
        return $staff->isPlatformAdmin()
            || ($staff->isRestaurantOwner() && $restaurant->id === $staff->restaurant_id);
    }

    public function delete(Staff $staff, Restaurant $restaurant): bool
    {
        return $staff->isPlatformAdmin();
    }
}
