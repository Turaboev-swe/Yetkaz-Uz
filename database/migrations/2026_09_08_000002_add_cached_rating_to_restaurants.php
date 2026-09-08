<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restoran reytingi keshi. `orders.rating` dan hisoblanadi
 * (RecalculateRestaurantRating job). Restoranlar ro'yxatida ko'rsatish/saralash
 * uchun — har so'rovda AVG hisoblamaslik uchun keshlanadi.
 *
 * Ko'rsatish sharti (Claude.md): average >= 4.0 VA count >= 5. Aks holda "Yangi".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->decimal('cached_average_rating', 2, 1)->nullable()->after('avg_prep_time_min');
            $table->unsignedInteger('cached_ratings_count')->default(0)->after('cached_average_rating');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn(['cached_average_rating', 'cached_ratings_count']);
        });
    }
};
