<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Har xodim (kitchen_staff / restaurant_owner) uchun shaxsiy Telegram
 * bildirishnoma manzili. Xodim botга /id yozib chiqqan raqamni panelга kiritadi.
 *
 * bigint (signed) — Telegram chat_id 64-bitгacha bo'lishi mumkin; guruh/kanal
 * chat_id manfiy bo'ladi, shaxsiy chatда musbat.
 *
 * Bu bosqichda faqat SAQLASH — xabar yuborish logikasi keyingi bosqichда.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->bigInteger('telegram_chat_id')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn('telegram_chat_id');
        });
    }
};
