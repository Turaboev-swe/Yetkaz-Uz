<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ma'muriy hududlar: viloyat -> tuman/shahar.
 *
 * Tuman koordinatani ALMASHTIRMAYDI — masofa, yetkazish radiusi va ETA faqat
 * restoran/manzil lat/lng dan hisoblanadi. `center_lat/lng` faqat xaritani
 * markazlashtirish va ko'rsatish uchun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 10); // ISO 3166-2 uslubidagi qisqa kod: AN
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('name');
            $table->unique('code');
        });

        Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('region_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('center_lat', 10, 7);
            $table->decimal('center_lng', 10, 7);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['region_id', 'name']);
            $table->index(['region_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('districts');
        Schema::dropIfExists('regions');
    }
};
