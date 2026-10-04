<?php

namespace App\Policies;

use App\Models\Banner;
use App\Models\Staff;

/** Bannerlar — faqat platforma admini boshqaradi (/admin). */
class BannerPolicy
{
    public function viewAny(Staff $staff): bool
    {
        return $staff->isPlatformAdmin();
    }

    public function view(Staff $staff, Banner $banner): bool
    {
        return $staff->isPlatformAdmin();
    }

    public function create(Staff $staff): bool
    {
        return $staff->isPlatformAdmin();
    }

    public function update(Staff $staff, Banner $banner): bool
    {
        return $staff->isPlatformAdmin();
    }

    public function delete(Staff $staff, Banner $banner): bool
    {
        return $staff->isPlatformAdmin();
    }
}
