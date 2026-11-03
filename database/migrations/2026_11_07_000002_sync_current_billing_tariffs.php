<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `restaurants.billing_*` = JORIY oy uchun amaldagi tarif (tarix bo'yicha `effective_from <= joriy oy`
 * eng oxirgi yozuv), kelajak yozuvi emas. Avval ustunlar eng oxirgi (ba'zan kelajak) yozuvga
 * sinxronlanardi — production'dagi shunday restoranlarni to'g'irlaydi.
 *
 * Faqat tarif tarixi bor restoranlarga tegadi; tarixsiz restoranlar o'zgarmaydi. Joriy oyda hali
 * amaldagi yozuv yo'q (faqat kelajak yozuvi bor) restoranda `billing_plan` NULL bo'ladi.
 * Ma'lumotni faqat sinxronlaydi (tarix va hisob-kitoblarga tegmaydi); idempotent. Shu mantiqning
 * doimiy varianti: `php artisan billing:sync-tariffs`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $month = now(config('app.display_timezone'))->startOfMonth()->format('Y-m-d');

        DB::statement(<<<'SQL'
            UPDATE restaurants r
            SET billing_plan = e.plan, commission_rate_bp = e.rate_bp, monthly_fee = e.monthly_fee
            FROM (
                SELECT DISTINCT ON (restaurant_id) restaurant_id, plan, rate_bp, monthly_fee
                FROM restaurant_billing_plans
                WHERE effective_from <= ?
                ORDER BY restaurant_id, effective_from DESC
            ) e
            WHERE e.restaurant_id = r.id
              AND (r.billing_plan IS DISTINCT FROM e.plan
                   OR r.commission_rate_bp IS DISTINCT FROM e.rate_bp
                   OR r.monthly_fee IS DISTINCT FROM e.monthly_fee)
        SQL, [$month]);

        // Faqat kelajak yozuvi bor restoranlar: hozircha tarifsiz.
        DB::statement(<<<'SQL'
            UPDATE restaurants r
            SET billing_plan = NULL
            WHERE r.billing_plan IS NOT NULL
              AND EXISTS (SELECT 1 FROM restaurant_billing_plans p WHERE p.restaurant_id = r.id)
              AND NOT EXISTS (SELECT 1 FROM restaurant_billing_plans p WHERE p.restaurant_id = r.id AND p.effective_from <= ?)
        SQL, [$month]);
    }

    public function down(): void
    {
        // Ma'lumot sinxroni — qaytarilmaydi.
    }
};
