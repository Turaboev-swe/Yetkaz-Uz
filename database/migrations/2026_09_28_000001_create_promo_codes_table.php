<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promokod — buyurtmaga chegirma beradi, xarajati platforma va restoran
 * o'rtasida `restaurant_share_percent` bo'yicha bo'linadi (restoranlar bilan
 * hisob-kitob uchun — Reports sahifasidagi "Promokodlar" hisoboti).
 *
 * `restaurant_id` NULL — kod istalgan restoranда ishlaydi; to'ldirilgan
 * bo'lsa — faqat o'sha restoranда.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32);
            // percent (1-100) | fixed (tiyin)
            $table->string('discount_type', 16)->default('percent');
            $table->unsignedInteger('discount_value');
            // Chegirma xarajatidan restoranga tegadigan ulush — qolgani platformaga.
            $table->unsignedTinyInteger('restaurant_share_percent')->default(50);
            $table->foreignId('restaurant_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->unique('code');
            $table->index('restaurant_id');
        });

        DB::statement("ALTER TABLE promo_codes ADD CONSTRAINT promo_codes_discount_type_check CHECK (discount_type IN ('percent','fixed'))");
        DB::statement('ALTER TABLE promo_codes ADD CONSTRAINT promo_codes_restaurant_share_check CHECK (restaurant_share_percent BETWEEN 0 AND 100)');
        DB::statement("ALTER TABLE promo_codes ADD CONSTRAINT promo_codes_value_check CHECK (
            (discount_type = 'percent' AND discount_value BETWEEN 1 AND 100)
            OR (discount_type = 'fixed' AND discount_value > 0)
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_codes');
    }
};
