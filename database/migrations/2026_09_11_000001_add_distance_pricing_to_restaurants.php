<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Masofaga qarab yetkazish narxi (ikkalasi ham IXTIYORIY):
 *
 * - free_delivery_radius_km — shu masofagacha yetkazish BEPUL. Bu
 *   `delivery_radius_km` (restoran umuman yetkazadigan MAKSIMAL masofa)
 *   BILAN ADASHTIRILMASIN — ikkisi mustaqil qiymatlar.
 * - price_per_km — km boshiga narx (tiyinda). To'ldirilsa, masofaga qarab
 *   hisoblanadi.
 *
 * Ikkalasi ham bo'sh qoldirilsa — mavjud qat'iy `delivery_fee` zaxira
 * sifatida ishlashda davom etadi (DeliveryFeeCalculator).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->decimal('free_delivery_radius_km', 4, 2)->nullable()->after('delivery_radius_km');
            $table->unsignedInteger('price_per_km')->nullable()->after('free_delivery_radius_km'); // tiyin/km
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn(['free_delivery_radius_km', 'price_per_km']);
        });
    }
};
