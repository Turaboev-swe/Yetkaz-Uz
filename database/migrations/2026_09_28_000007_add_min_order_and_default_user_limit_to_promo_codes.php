<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promokod shartlari:
 *   - min_order_amount (tiyinда, NULL — cheklovsiz): faqat TAOMLAR summasi
 *     (subtotal) bilan solishtiriladi, yetkazish narxi qo'shilmaydi;
 *   - per_user_limit standarti 1 — har mijoz bir marta (bekor qilingan
 *     buyurtma hisoblanmaydi). Mavjud kodlarning qiymati o'zgarmaydi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promo_codes', function (Blueprint $table) {
            $table->unsignedBigInteger('min_order_amount')->nullable()->after('discount_value');
        });

        DB::statement('ALTER TABLE promo_codes ADD CONSTRAINT promo_codes_min_order_amount_check CHECK (min_order_amount IS NULL OR min_order_amount >= 0)');
        DB::statement('ALTER TABLE promo_codes ALTER COLUMN per_user_limit SET DEFAULT 1');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE promo_codes ALTER COLUMN per_user_limit DROP DEFAULT');
        DB::statement('ALTER TABLE promo_codes DROP CONSTRAINT IF EXISTS promo_codes_min_order_amount_check');

        Schema::table('promo_codes', function (Blueprint $table) {
            $table->dropColumn('min_order_amount');
        });
    }
};
