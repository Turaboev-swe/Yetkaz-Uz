<?php

namespace App\Policies;

use App\Models\RestaurantInvoice;
use App\Models\Staff;

/**
 * Obuna hisob-kitoblari — faqat platforma admini. Hisob-kitob qo'lda yaratilmaydi,
 * tahrirlanmaydi va o'chirilmaydi: faqat maxsus amallar (to'lov, qayta hisoblash, tarifni o'zgartirish, kechirish).
 */
class RestaurantInvoicePolicy
{
    public function viewAny(Staff $staff): bool
    {
        return $staff->isPlatformAdmin();
    }

    public function view(Staff $staff, RestaurantInvoice $invoice): bool
    {
        return $staff->isPlatformAdmin();
    }

    public function create(Staff $staff): bool
    {
        return false;
    }

    public function update(Staff $staff, RestaurantInvoice $invoice): bool
    {
        return false;
    }

    public function delete(Staff $staff, RestaurantInvoice $invoice): bool
    {
        return false;
    }

    public function addPayment(Staff $staff, RestaurantInvoice $invoice): bool
    {
        return $staff->isPlatformAdmin();
    }

    public function recalculate(Staff $staff, RestaurantInvoice $invoice): bool
    {
        return $staff->isPlatformAdmin();
    }

    public function changeTariff(Staff $staff, RestaurantInvoice $invoice): bool
    {
        return $staff->isPlatformAdmin();
    }

    public function waive(Staff $staff, RestaurantInvoice $invoice): bool
    {
        return $staff->isPlatformAdmin();
    }
}
