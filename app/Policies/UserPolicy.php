<?php

namespace App\Policies;

use App\Models\Staff;
use App\Models\User;

/**
 * `users` — Telegram mijozlari. Faqat platforma admini ko'radi (/admin,
 * Mijozlar bo'limi). Bu yerda tahrirlash/o'chirish YO'Q — profil ma'lumoti
 * faqat bot orqali (ProfileService) o'zgaradi, admin panel orqali emas.
 *
 * Restoran egasi/oshxona xodimi mijoz ro'yxatini ko'rmaydi — faqat o'ziga
 * tegishli buyurtmalardagi mijoz ismi/telefoni (OrderResource orqali).
 */
class UserPolicy
{
    public function viewAny(Staff $staff): bool
    {
        return $staff->isPlatformAdmin();
    }

    public function view(Staff $staff, User $user): bool
    {
        return $staff->isPlatformAdmin();
    }

    public function create(Staff $staff): bool
    {
        return false;
    }

    public function update(Staff $staff, User $user): bool
    {
        return false;
    }

    public function delete(Staff $staff, User $user): bool
    {
        return false;
    }
}
