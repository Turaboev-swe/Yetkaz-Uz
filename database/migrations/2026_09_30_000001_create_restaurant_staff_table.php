<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Oshxona xodimi bir nechta restoranga biriktiriladi (masalan bir egasining
 * ikki restorani — bitta /kitchen panelida).
 *
 * `staff.restaurant_id` O'CHIRILMAYDI — u asosiy restoran: /restaurant paneli
 * va RestaurantScope shunga qaraydi. Pivot esa oshxona (buyurtmalar, kanal,
 * bildirishnomalar) uchun. Mavjud har bir staff.restaurant_id pivot'ga
 * ko'chiriladi — bir restoranli xodimlar avvalgidek ishlaydi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['staff_id', 'restaurant_id']);
            $table->index('restaurant_id');
        });

        DB::statement('
            INSERT INTO restaurant_staff (staff_id, restaurant_id, created_at, updated_at)
            SELECT id, restaurant_id, NOW(), NOW() FROM staff WHERE restaurant_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_staff');
    }
};
