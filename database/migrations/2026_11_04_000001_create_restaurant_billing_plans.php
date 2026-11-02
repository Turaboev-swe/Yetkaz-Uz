<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restoran tarif tarixi: har bir davr uchun amalda bo'lgan tarif (effective_from <= davr bo'lgan
 * eng oxirgi yozuv). `restaurants.billing_*` ustunlari "joriy tarif" sifatida qoladi (forma uchun).
 *
 * `plan = NULL` — "tarifsiz" davr (shu oydan boshlab hisoblanmaydi).
 * Data migration: billing_plan belgilangan restoranlar uchun boshlang'ich yozuv (idempotent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_billing_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('plan', 16)->nullable();
            $table->unsignedInteger('rate_bp')->default(500);
            $table->unsignedBigInteger('monthly_fee')->default(10000000); // tiyin
            $table->date('effective_from'); // har doim oyning 1-kuni (Asia/Tashkent)
            $table->foreignId('created_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();

            $table->unique(['restaurant_id', 'effective_from']);
        });

        DB::statement("ALTER TABLE restaurant_billing_plans ADD CONSTRAINT restaurant_billing_plans_plan_check CHECK (plan IS NULL OR plan IN ('percent','fixed'))");
        DB::statement('ALTER TABLE restaurant_billing_plans ADD CONSTRAINT restaurant_billing_plans_rate_check CHECK (rate_bp BETWEEN 0 AND 10000)');
        DB::statement("ALTER TABLE restaurant_billing_plans ADD CONSTRAINT restaurant_billing_plans_month_start_check CHECK (EXTRACT(DAY FROM effective_from) = 1)");

        // Boshlang'ich yozuvlar: faqat tarifi belgilangan restoranlar uchun; billing_started_at oyidan
        // (bo'lmasa billing.start_date oyidan). Qayta ishga tushirilsa mavjudiga tegmaydi.
        $start = (string) config('billing.start_date', '2026-11-01');

        DB::statement(
            "INSERT INTO restaurant_billing_plans (restaurant_id, plan, rate_bp, monthly_fee, effective_from, created_at, updated_at)
             SELECT id, billing_plan, commission_rate_bp, monthly_fee,
                    date_trunc('month', COALESCE(billing_started_at, ?::date))::date, NOW(), NOW()
             FROM restaurants
             WHERE billing_plan IS NOT NULL
             ON CONFLICT (restaurant_id, effective_from) DO NOTHING",
            [$start],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_billing_plans');
    }
};
