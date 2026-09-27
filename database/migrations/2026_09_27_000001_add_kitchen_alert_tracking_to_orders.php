<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qabul qilinmagan buyurtma ogohlantirishlarini kuzatish:
 *
 * - last_push_at — RepeatKitchenPush oxirgi takroriy push vaqti. Atomik
 *   "UPDATE ... WHERE last_push_at IS NULL OR last_push_at <= ?" bilan
 *   egallanadi — ikkita parallel zanjir bo'lib qolsa ham har intervalda
 *   bitta push, ortiqcha zanjir o'zi to'xtaydi.
 * - admin_alerted_at — platforma adminiga bir martalik ogohlantirish
 *   (AlertAdminOfUnacceptedOrder) yuborilgan vaqt; null bo'lsagina yuboriladi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('last_push_at')->nullable();
            $table->timestamp('admin_alerted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['last_push_at', 'admin_alerted_at']);
        });
    }
};
