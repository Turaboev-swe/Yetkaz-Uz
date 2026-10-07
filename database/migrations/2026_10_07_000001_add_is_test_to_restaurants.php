<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doimiy test restorani belgisi. `is_test = true` restoran faqat
 * TEST_TELEGRAM_IDS dagi hisoblarga ko'rinadi va uning buyurtmalari
 * hisobot/statistikaga kirmaydi. Default false — mavjud restoranlar o'zgarmaydi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->boolean('is_test')->default(false)->after('is_open');
            // Hisobotlarda "test emas" filtri va ro'yxat so'rovlari uchun.
            $table->index('is_test');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropIndex(['is_test']);
            $table->dropColumn('is_test');
        });
    }
};
