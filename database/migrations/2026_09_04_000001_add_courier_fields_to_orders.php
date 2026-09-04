<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kuryer ma'lumoti — buyurtma "yo'lga chiqdi" bo'lganда oshxona kiritadi
 * (ixtiyoriy). To'ldirilgan bo'lsa mijozga status xabarida yuboriladi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('courier_name', 100)->nullable()->after('note');
            $table->string('courier_phone', 32)->nullable()->after('courier_name');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['courier_name', 'courier_phone']);
        });
    }
};
