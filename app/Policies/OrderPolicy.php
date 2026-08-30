<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\Staff;

class OrderPolicy
{
    public function viewAny(Staff $staff): bool
    {
        return $staff->isPlatformAdmin() || $staff->isRestaurantOwner();
    }

    public function view(Staff $staff, Order $order): bool
    {
        if ($staff->isPlatformAdmin()) {
            return true;
        }

        return $staff->isRestaurantOwner()
            && $order->restaurant_id === $staff->restaurant_id;
    }

    // Buyurtmalar panelda yaratilmaydi / tahrirlanmaydi / o'chirilmaydi.
    public function create(Staff $staff): bool
    {
        return false;
    }

    public function update(Staff $staff, Order $order): bool
    {
        return false;
    }

    public function delete(Staff $staff, Order $order): bool
    {
        return false;
    }
}
