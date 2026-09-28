<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promokod faqat admin TANLAGAN restoranlarda ishlaydi (ko'p-ko'pga).
 *
 * Avval bitta promo_codes.restaurant_id bor edi: NULL = barcha restoranlar.
 * Yangi qoida: "Barcha restoranlar" varianti YO'Q — rozi bo'lmagan restoranga
 * tasodifan tushib qolmasligi uchun. Shuning uchun:
 *   - to'ldirilgan restaurant_id pivot'ga ko'chiriladi;
 *   - NULL ("hammaga") kodlar barcha restoranlarga KENGAYTIRILMAYDI — ular
 *     restoransiz qoladi (hech qayerda ishlamaydi) va admin restoranlarni
 *     tanlaguncha kutadi. (Ko'chirish paytida: lokalda 0 ta kod, production'da
 *     promokod funksiyasi hali deploy qilinmagan.)
 *
 * Eski FK `ON DELETE SET NULL` edi — restoran o'chirilsa kod jimgina HAMMA
 * restoranda ishlay boshlardi. Pivot'da restoran o'chirilsa faqat uning
 * qatori o'chadi (cascade) — kod hech qachon "hammaga" aylanmaydi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_code_restaurant', function (Blueprint $table) {
            $table->foreignId('promo_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();

            $table->primary(['promo_code_id', 'restaurant_id']);
            $table->index('restaurant_id');
        });

        DB::statement('
            INSERT INTO promo_code_restaurant (promo_code_id, restaurant_id)
            SELECT id, restaurant_id FROM promo_codes WHERE restaurant_id IS NOT NULL
        ');

        Schema::table('promo_codes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('restaurant_id');
        });
    }

    public function down(): void
    {
        Schema::table('promo_codes', function (Blueprint $table) {
            $table->foreignId('restaurant_id')->nullable()->after('restaurant_share_percent')
                ->constrained()->nullOnDelete();
            $table->index('restaurant_id');
        });

        // Bitta ustunga faqat bitta restoran sig'adi — bir nechta tanlangan
        // bo'lsa eng kichik id qoladi (orqaga qaytarishdagi muqarrar yo'qotish).
        DB::statement('
            UPDATE promo_codes p SET restaurant_id = (
                SELECT MIN(r.restaurant_id) FROM promo_code_restaurant r WHERE r.promo_code_id = p.id
            )
        ');

        Schema::dropIfExists('promo_code_restaurant');
    }
};
