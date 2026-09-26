<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Taomning AI taxmin qilgan kaloriyasi va "yengil taom" belgisi.
 * Mijozga FAQAT restoran egasi tasdiqlagach (nutrition_status = approved)
 * ko'rsatiladi. Hammasi nullable — mavjud taomlarga ta'sir yo'q
 * (null = hali taxmin qilinmagan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('calories_estimate')->nullable(); // bir porsiya, kkal
            $table->boolean('is_light')->nullable();
            $table->string('nutrition_status', 16)->nullable()->index(); // pending | approved | hidden
            $table->timestamp('nutrition_generated_at')->nullable();
        });

        DB::statement("ALTER TABLE products ADD CONSTRAINT products_nutrition_status_check CHECK (nutrition_status IN ('pending','approved','hidden'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_nutrition_status_check');

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['nutrition_status']);
            $table->dropColumn(['calories_estimate', 'is_light', 'nutrition_status', 'nutrition_generated_at']);
        });
    }
};
