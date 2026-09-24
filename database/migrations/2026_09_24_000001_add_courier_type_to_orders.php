<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kuryer turi — "Yo'lga chiqdi"da tanlanadi: restoran o'z xodimi bilan
 * yetkazadimi, yoki Royal Taxi orqalimi. Nullable — eski buyurtmalarga
 * (courier_type kiritilishidan oldingi) ta'sir qilmaydi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('courier_type', 16)->nullable()->after('courier_staff_id');
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_courier_type_check CHECK (courier_type IN ('own_staff','taxi'))");
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('courier_type');
        });
    }
};
