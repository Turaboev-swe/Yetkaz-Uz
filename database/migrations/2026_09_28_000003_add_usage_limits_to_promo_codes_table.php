<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promokod ishlatilish limitlari. NULL — cheklovsiz.
 *
 * Ishlatilish = shu kod bilan yaratilgan, BEKOR QILINMAGAN buyurtma
 * (PromoCode::usages). Bekor qilinsa limit qaytadi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promo_codes', function (Blueprint $table) {
            $table->unsignedInteger('per_user_limit')->nullable()->after('restaurant_share_percent');
            $table->unsignedInteger('total_usage_limit')->nullable()->after('per_user_limit');
        });

        DB::statement('ALTER TABLE promo_codes ADD CONSTRAINT promo_codes_per_user_limit_check CHECK (per_user_limit IS NULL OR per_user_limit > 0)');
        DB::statement('ALTER TABLE promo_codes ADD CONSTRAINT promo_codes_total_usage_limit_check CHECK (total_usage_limit IS NULL OR total_usage_limit > 0)');

        // Limit tekshiruvi: "shu kod bo'yicha (shu mijozning) bekor qilinmagan buyurtmalari soni".
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['promo_code_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['promo_code_id', 'user_id']);
        });

        DB::statement('ALTER TABLE promo_codes DROP CONSTRAINT IF EXISTS promo_codes_per_user_limit_check');
        DB::statement('ALTER TABLE promo_codes DROP CONSTRAINT IF EXISTS promo_codes_total_usage_limit_check');

        Schema::table('promo_codes', function (Blueprint $table) {
            $table->dropColumn(['per_user_limit', 'total_usage_limit']);
        });
    }
};
