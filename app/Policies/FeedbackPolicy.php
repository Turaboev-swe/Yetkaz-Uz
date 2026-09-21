<?php

namespace App\Policies;

use App\Models\Feedback;
use App\Models\Staff;

/**
 * `feedbacks` — bot orqali kelgan taklif/shikoyatlar. Faqat platforma admini
 * ko'radi. Tahrirlash/o'chirish yo'q — bu mijozning o'zgarmas xabari.
 */
class FeedbackPolicy
{
    public function viewAny(Staff $staff): bool
    {
        return $staff->isPlatformAdmin();
    }

    public function view(Staff $staff, Feedback $feedback): bool
    {
        return $staff->isPlatformAdmin();
    }

    public function create(Staff $staff): bool
    {
        return false;
    }

    public function update(Staff $staff, Feedback $feedback): bool
    {
        return false;
    }

    public function delete(Staff $staff, Feedback $feedback): bool
    {
        return false;
    }
}
