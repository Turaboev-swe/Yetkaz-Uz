<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mahsulot qoldig'i (stock). Faqat QO'SHILADI — barcha ustunlar NULL, default yo'q, mavjud ma'lumotga tegilmaydi
 * (PostgreSQL'da bunday ADD COLUMN jadvalni qayta yozmaydi).
 *
 * products:
 *   stock_quantity   NULL = cheklanmagan (hozirgi xatti-harakat). 0 = "Tugadi" (hisoblanadi, is_available ga tegilmaydi).
 *   daily_stock      har kuni shu songa tiklanadigan qoldiq (NULL = kunlik tiklash yo'q).
 *   daily_reset_time tiklanish vaqti (Asia/Tashkent devor soati).
 *   stock_reset_at   oxirgi tiklanish (yoki sozlama saqlangan) lahzasi, UTC — tiklash kuniga bir marta bo'lishi
 *                    uchun va eski buyurtma bugungi qoldiqni oshirmasligi uchun.
 * orders:
 *   stock_reserved   buyurtma HAQIQATAN kamaytirgan miqdorlar {product_id: qty}. Qaytarish shu xaritadan ayiriladi,
 *                    shuning uchun ikki marta qaytmaydi; cheksiz mahsulotga yozilmaydi.
 *   stock_released_at hammasi qaytarilgan lahza (audit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->integer('stock_quantity')->nullable();
            $table->integer('daily_stock')->nullable();
            $table->time('daily_reset_time')->nullable();
            $table->timestamp('stock_reset_at')->nullable();
        });

        // Qoldiq bazada hech qachon manfiy bo'lmaydi (atomik UPDATE ... WHERE stock_quantity >= qty bilan birga).
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_stock_quantity_check CHECK (stock_quantity IS NULL OR stock_quantity >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_daily_stock_check CHECK (daily_stock IS NULL OR daily_stock >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_daily_reset_requires_time_check CHECK (daily_stock IS NULL OR daily_reset_time IS NOT NULL)');

        // Scheduler har daqiqa shu ro'yxatni so'raydi — kunlik tiklash sozlangan kam sonli qator.
        DB::statement('CREATE INDEX products_daily_reset_idx ON products (daily_reset_time) WHERE daily_stock IS NOT NULL');

        Schema::table('orders', function (Blueprint $table) {
            $table->jsonb('stock_reserved')->nullable();
            $table->timestamp('stock_released_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['stock_reserved', 'stock_released_at']);
        });

        DB::statement('DROP INDEX IF EXISTS products_daily_reset_idx');
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_daily_reset_requires_time_check');
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_daily_stock_check');
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_stock_quantity_check');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['stock_quantity', 'daily_stock', 'daily_reset_time', 'stock_reset_at']);
        });
    }
};
