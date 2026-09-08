<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `resolved_address` — Nominatim reverse geocoding'dan olingan o'qiladigan manzil
 * matni ("Bobur ko'chasi 12, Qo'rg'ontepa tumani"). Manzil yaratilganda BIR MARTA
 * hisoblanib keshlanadi. Mini App tepasida label ("Uy") o'rniga shu ko'rsatiladi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->string('resolved_address', 255)->nullable()->after('address_text');
        });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropColumn('resolved_address');
        });
    }
};
