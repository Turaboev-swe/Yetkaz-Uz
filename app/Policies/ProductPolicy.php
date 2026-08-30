<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\Product;
use App\Models\Staff;

class ProductPolicy
{
    public function viewAny(Staff $staff): bool
    {
        return $staff->isPlatformAdmin() || $staff->isRestaurantOwner();
    }

    public function view(Staff $staff, Product $product): bool
    {
        return $this->owns($staff, $product);
    }

    public function create(Staff $staff): bool
    {
        return $staff->isRestaurantOwner() || $staff->isPlatformAdmin();
    }

    public function update(Staff $staff, Product $product): bool
    {
        return $this->owns($staff, $product);
    }

    public function delete(Staff $staff, Product $product): bool
    {
        return $this->owns($staff, $product);
    }

    private function owns(Staff $staff, Product $product): bool
    {
        if ($staff->isPlatformAdmin()) {
            return true;
        }

        if (! $staff->isRestaurantOwner()) {
            return false;
        }

        $restaurantId = Category::withoutGlobalScopes()
            ->whereKey($product->category_id)
            ->value('restaurant_id');

        return $restaurantId === $staff->restaurant_id;
    }
}
