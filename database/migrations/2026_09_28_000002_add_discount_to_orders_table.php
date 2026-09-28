<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promokod qo'llangan buyurtmaning chegirma snapshoti (tiyinda).
 * `discount_amount` allaqachon `orders.total` ichida ayirilgan — bu ustunlar
 * FAQAT hisobot/hisob-kitob uchun (restoran ulushi/platforma ulushi).
 *
 * Barchasi default 0 — promokodsiz buyurtmada ham qiymat bor (NULL emas),
 * SUM() so'rovlarida COALESCE shart emas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('promo_code_id')->nullable()->after('address_id')
                ->constrained()->nullOnDelete();
            $table->unsignedBigInteger('discount_amount')->default(0)->after('total');
            $table->unsignedBigInteger('discount_restaurant_share')->default(0)->after('discount_amount');
            $table->unsignedBigInteger('discount_platform_share')->default(0)->after('discount_restaurant_share');

            $table->index('promo_code_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promo_code_id');
            $table->dropColumn(['discount_amount', 'discount_restaurant_share', 'discount_platform_share']);
        });
    }
};
