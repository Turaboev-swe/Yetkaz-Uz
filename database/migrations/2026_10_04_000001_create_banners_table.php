<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mini App bosh sahifasidagi reklama bannerlari (karusel). Faqat platforma
 * admini boshqaradi. `title` — faqat panel uchun ichki nom, mijozga ko'rinmaydi.
 *
 * `target_type`: none — bosilganda hech narsa; restaurant — `restaurant_id`
 * menyusi ochiladi. starts_at/ends_at — UTC (panelда Toshkent vaqtida kiritiladi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('image_path');
            $table->string('title', 120);
            $table->string('target_type', 16)->default('none');
            // Restoran o'chirilsa banneri ham o'chadi — mavjud bo'lmagan menyuga olib boradigan banner ma'nosiz.
            $table->foreignId('restaurant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->integer('sort_order')->default(0);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
            $table->index('restaurant_id');
        });

        DB::statement("ALTER TABLE banners ADD CONSTRAINT banners_target_type_check CHECK (target_type IN ('none','restaurant'))");
        DB::statement("ALTER TABLE banners ADD CONSTRAINT banners_restaurant_target_check CHECK (
            (target_type = 'restaurant' AND restaurant_id IS NOT NULL)
            OR (target_type = 'none' AND restaurant_id IS NULL)
        )");
        DB::statement('ALTER TABLE banners ADD CONSTRAINT banners_period_check CHECK (ends_at IS NULL OR ends_at > starts_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
