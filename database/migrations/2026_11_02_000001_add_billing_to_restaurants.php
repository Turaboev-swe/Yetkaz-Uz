<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restoran obuna tarifi. `billing_plan = NULL` — "tarif belgilanmagan": mavjud restoranlarga
 * tarif AVTOMAT qo'yilmaydi, admin /admin dan qo'lda belgilaydi.
 *
 *  - billing_plan: percent | fixed | NULL
 *  - commission_rate_bp: foiz, basis points (500 = 5%)
 *  - monthly_fee: qat'iy oylik to'lov, tiyinda (10 000 000 = 100 000 so'm)
 *  - billing_started_at: tarif amal qila boshlagan sana (Asia/Tashkent)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('billing_plan', 16)->nullable()->after('is_test');
            $table->unsignedInteger('commission_rate_bp')->default(500)->after('billing_plan');
            $table->unsignedBigInteger('monthly_fee')->default(10_000_000)->after('commission_rate_bp');
            $table->date('billing_started_at')->nullable()->after('monthly_fee');
        });

        DB::statement("ALTER TABLE restaurants ADD CONSTRAINT restaurants_billing_plan_check CHECK (billing_plan IS NULL OR billing_plan IN ('percent','fixed'))");
        DB::statement('ALTER TABLE restaurants ADD CONSTRAINT restaurants_commission_rate_bp_check CHECK (commission_rate_bp BETWEEN 0 AND 10000)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE restaurants DROP CONSTRAINT IF EXISTS restaurants_commission_rate_bp_check');
        DB::statement('ALTER TABLE restaurants DROP CONSTRAINT IF EXISTS restaurants_billing_plan_check');

        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn(['billing_plan', 'commission_rate_bp', 'monthly_fee', 'billing_started_at']);
        });
    }
};
