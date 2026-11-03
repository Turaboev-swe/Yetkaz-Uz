<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hisob-kitobga `void` (bekor qilingan) holati: xato tarif bilan yaratilgan, to'lovsiz hisob
 * tuzatishda o'chirilmaydi, `void` ga o'tkaziladi (izohda sabab/kim/qachon).
 *
 * unique(restaurant_id, period) -> PARTIAL unique index (WHERE status <> 'void'): bekor qilingan
 * hisob o'sha oy uchun yangi hisob yaratilishiga to'sqinlik qilmaydi, faol hisob esa baribir bitta.
 * Faqat sxema o'zgaradi, mavjud ma'lumotga tegilmaydi.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE restaurant_invoices DROP CONSTRAINT IF EXISTS restaurant_invoices_status_check');
        DB::statement("ALTER TABLE restaurant_invoices ADD CONSTRAINT restaurant_invoices_status_check CHECK (status IN ('unpaid','partial','paid','waived','void'))");

        DB::statement('ALTER TABLE restaurant_invoices DROP CONSTRAINT IF EXISTS restaurant_invoices_restaurant_id_period_unique');
        DB::statement('DROP INDEX IF EXISTS restaurant_invoices_restaurant_id_period_unique');
        DB::statement("CREATE UNIQUE INDEX restaurant_invoices_restaurant_period_active_unique ON restaurant_invoices (restaurant_id, period) WHERE status <> 'void'");
    }

    public function down(): void
    {
        // Bekor qilingan hisoblar qaytishda bir oyga ikkita qator bo'lib qolishi mumkin — avval ularni olib tashlaymiz.
        $voidIds = DB::table('restaurant_invoices')->where('status', 'void')->pluck('id');
        DB::table('restaurant_payments')->whereIn('invoice_id', $voidIds)->delete();
        DB::table('restaurant_invoices')->whereIn('id', $voidIds)->delete();

        DB::statement('DROP INDEX IF EXISTS restaurant_invoices_restaurant_period_active_unique');
        DB::statement('ALTER TABLE restaurant_invoices ADD CONSTRAINT restaurant_invoices_restaurant_id_period_unique UNIQUE (restaurant_id, period)');

        DB::statement('ALTER TABLE restaurant_invoices DROP CONSTRAINT IF EXISTS restaurant_invoices_status_check');
        DB::statement("ALTER TABLE restaurant_invoices ADD CONSTRAINT restaurant_invoices_status_check CHECK (status IN ('unpaid','partial','paid','waived'))");
    }
};
