<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kuryer sifatida tanlangan xodim. `courier_name` / `courier_phone` — tanlangan
 * paytdagi SNAPSHOT (xodim keyin ma'lumotini o'zgartirsa eski buyurtmada asl qoladi).
 * Xodim o'chirilsa bog'lanish uziladi, snapshot qoladi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('courier_staff_id')->nullable()->after('courier_phone')
                ->constrained('staff')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('courier_staff_id');
        });
    }
};
