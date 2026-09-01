<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chek chiqarish (print agent):
 *  - restaurants.print_agent_token — oshxona kompyuteridagi agent shu token bilan
 *    Reverb kanaliga autentifikatsiya qiladi va `printed_at` tasdiqini yuboradi
 *  - orders.dispatch_failed_at — chek 3 urinishдан keyin ham chiqmadi (ogohlantirish)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('print_agent_token', 64)->nullable()->unique()->after('printer_port');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('dispatch_failed_at')->nullable()->after('printed_at');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', fn (Blueprint $table) => $table->dropColumn('print_agent_token'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('dispatch_failed_at'));
    }
};
