<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram bildirishnomasi:
 *  - users.username — mijozning @username (bildirishnomaga qo'shiladi)
 *  - restaurants.notify_chat_id — yangi buyurtma haqida bot shu chat'ga yozadi
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 64)->nullable()->after('phone');
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('notify_chat_id', 32)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('username'));
        Schema::table('restaurants', fn (Blueprint $table) => $table->dropColumn('notify_chat_id'));
    }
};
