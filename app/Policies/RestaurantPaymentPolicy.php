<?php

namespace App\Policies;

use App\Models\RestaurantPayment;
use App\Models\Staff;

/**
 * To'lovlar tarixi (platforma admini). To'lov faqat InvoiceService orqali qo'shiladi;
 * o'chirilmaydi, faqat bekor qilinadi (cancel).
 */
class RestaurantPaymentPolicy
{
    public function viewAny(Staff $staff): bool
    {
        return $staff->isPlatformAdmin();
    }

    public function view(Staff $staff, RestaurantPayment $payment): bool
    {
        return $staff->isPlatformAdmin();
    }

    public function create(Staff $staff): bool
    {
        return false;
    }

    public function update(Staff $staff, RestaurantPayment $payment): bool
    {
        return false;
    }

    public function cancel(Staff $staff, RestaurantPayment $payment): bool
    {
        return $staff->isPlatformAdmin();
    }

    public function delete(Staff $staff, RestaurantPayment $payment): bool
    {
        return false;
    }
}
