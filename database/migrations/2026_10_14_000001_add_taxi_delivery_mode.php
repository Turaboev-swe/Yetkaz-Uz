<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Royal Taxi bilan ishlaydigan restoranlar: bepul zonadan tashqarida ilovada
 * yetkazish narxi olinmaydi (mijoz taksi haqini haydovchiga alohida to'laydi).
 *
 *  - restaurants.beyond_free_zone_delivery: per_km (standart — hozirgi xatti-harakat) | taxi
 *  - orders.delivery_paid_to_taxi: buyurtma yaratilgan paytdagi SNAPSHOT — restoran
 *    sozlamasi keyin o'zgarsa ham eski buyurtmalar o'zgarmaydi (default false)
 *
 * Mavjud restoranlar per_km, mavjud buyurtmalar false bo'lib qoladi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('beyond_free_zone_delivery', 16)->default('per_km')->after('price_per_km');
        });

        DB::statement("ALTER TABLE restaurants ADD CONSTRAINT restaurants_beyond_free_zone_delivery_check CHECK (beyond_free_zone_delivery IN ('per_km','taxi'))");

        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('delivery_paid_to_taxi')->default(false)->after('delivery_fee');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('delivery_paid_to_taxi');
        });

        DB::statement('ALTER TABLE restaurants DROP CONSTRAINT IF EXISTS restaurants_beyond_free_zone_delivery_check');

        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn('beyond_free_zone_delivery');
        });
    }
};
