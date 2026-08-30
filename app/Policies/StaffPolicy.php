<?php

namespace App\Policies;

use App\Models\Staff;

class StaffPolicy
{
    public function viewAny(Staff $staff): bool
    {
        return $staff->isPlatformAdmin();
    }

    public function view(Staff $staff, Staff $model): bool
    {
        return $staff->isPlatformAdmin();
    }

    public function create(Staff $staff): bool
    {
        return $staff->isPlatformAdmin();
    }

    public function update(Staff $staff, Staff $model): bool
    {
        return $staff->isPlatformAdmin();
    }

    public function delete(Staff $staff, Staff $model): bool
    {
        // O'zini o'chira olmaydi.
        return $staff->isPlatformAdmin() && $staff->id !== $model->id;
    }
}
