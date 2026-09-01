<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chegirmali narx: `old_price` (tiyin, nullable). To'ldirilgan va `price` dan
 * katta bo'lsa — taom aksiyada; Mini App eski narxni ustidan chizib ko'rsatadi.
 *
 * Eslatma: bu "chegirma" ni eng oddiy ko'rinishда saqlaydi (alohida promo
 * jadvalisiz). Agar oldingi spetsifikatsiyada boshqa tuzilma bo'lsa —
 * shu migratsiyani almashtiring.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('old_price')->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('old_price');
        });
    }
};
