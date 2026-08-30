<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\Staff;

class CategoryPolicy
{
    public function viewAny(Staff $staff): bool
    {
        return $staff->isPlatformAdmin() || $staff->isRestaurantOwner();
    }

    public function view(Staff $staff, Category $category): bool
    {
        return $this->owns($staff, $category);
    }

    public function create(Staff $staff): bool
    {
        return $staff->isRestaurantOwner() || $staff->isPlatformAdmin();
    }

    public function update(Staff $staff, Category $category): bool
    {
        return $this->owns($staff, $category);
    }

    public function delete(Staff $staff, Category $category): bool
    {
        return $this->owns($staff, $category);
    }

    private function owns(Staff $staff, Category $category): bool
    {
        if ($staff->isPlatformAdmin()) {
            return true;
        }

        return $staff->isRestaurantOwner()
            && $category->restaurant_id === $staff->restaurant_id;
    }
}
