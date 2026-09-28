<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promokod chegirmasi taqsimoti bo'yicha QAROR:
 *
 * - har kod uchun admin restoran qoplaydigan ulushni belgilaydi (0-100%),
 *   qolganini platforma qoplaydi. Standart 0 — hammasini platforma qoplaydi;
 * - taqsimot buyurtma yaratilganda orders'ga SNAPSHOT qilinadi
 *   (discount_restaurant_amount / discount_platform_amount) — kod ulushi keyin
 *   o'zgarsa ham eski buyurtma va hisobotlar o'zgarmaydi;
 * - summalar BUTUN SO'M (tiyinда saqlanadi, 100 ga karrali) va ikkala ulush
 *   yig'indisi har doim discount_amount'ga teng — CHECK bilan bazada kafolatlangan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('discount_restaurant_share', 'discount_restaurant_amount');
            $table->renameColumn('discount_platform_share', 'discount_platform_amount');
        });

        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_discount_whole_som_check CHECK (
            discount_amount % 100 = 0 AND discount_restaurant_amount % 100 = 0 AND discount_platform_amount % 100 = 0
        )');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_discount_split_sum_check CHECK (
            discount_restaurant_amount + discount_platform_amount = discount_amount
        )');

        DB::statement('ALTER TABLE promo_codes ALTER COLUMN restaurant_share_percent SET DEFAULT 0');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE promo_codes ALTER COLUMN restaurant_share_percent SET DEFAULT 50');

        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_discount_split_sum_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_discount_whole_som_check');

        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('discount_restaurant_amount', 'discount_restaurant_share');
            $table->renameColumn('discount_platform_amount', 'discount_platform_share');
        });
    }
};
