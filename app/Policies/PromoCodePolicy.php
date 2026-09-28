<?php

namespace App\Policies;

use App\Models\PromoCode;
use App\Models\Staff;

/**
 * Promokodlar — faqat platforma admini boshqaradi (/admin). Restoran egasi
 * o'z buyurtmasida qo'llangan chegirmani ko'radi (OrderResource infolist),
 * lekin promokod yaratish/tahrirlash huquqiga ega emas.
 */
class PromoCodePolicy
{
    public function viewAny(Staff $staff): bool
    {
        return $staff->isPlatformAdmin();
    }

    public function view(Staff $staff, PromoCode $promoCode): bool
    {
        return $staff->isPlatformAdmin();
    }

    public function create(Staff $staff): bool
    {
        return $staff->isPlatformAdmin();
    }

    public function update(Staff $staff, PromoCode $promoCode): bool
    {
        return $staff->isPlatformAdmin();
    }

    public function delete(Staff $staff, PromoCode $promoCode): bool
    {
        return $staff->isPlatformAdmin();
    }
}
