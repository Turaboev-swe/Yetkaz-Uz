<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rasmli xabarnoma uchun Telegram file_id. Birinchi muvaffaqiyatli sendPhoto
 * javobidan olinadi — keyingi foydalanuvchilarga rasm qayta yuklanmaydi,
 * shu file_id (oddiy string) yuboriladi. 2026-09-26: rasm har foydalanuvchiga
 * qayta yuklangani sabab 422 xatoning 320 tasi cURL timeout bo'lgan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->string('telegram_file_id')->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->dropColumn('telegram_file_id');
        });
    }
};
