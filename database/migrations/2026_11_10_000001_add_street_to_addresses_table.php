<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * addresses.street — ko'cha nomi (geokoder topgan yoki mijoz kiritgan). address_text = ko'cha + qishloq/mahalla + tuman.
 * Faqat nullable ustun: mavjud manzillarga tegilmaydi, backfill yo'q.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->string('street', 255)->nullable()->after('address_text');
        });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropColumn('street');
        });
    }
};
