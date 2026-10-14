<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aloqa raqami: Telegram raqami (`users.phone`) boshqa birovniki yoki o'lik
 * bo'lishi mumkin. Qo'lda kiritiladigan raqam ALOHIDA maydonlarda:
 *   - users.contact_phone  — keyingi buyurtmalar uchun standart (oxirgi ishlatilgani)
 *   - orders.contact_phone — shu buyurtma uchun (snapshot)
 * `users.phone` hech qachon o'zgarmaydi. Ikkalasi ham nullable.
 *
 * Mavjud foydalanuvchilar: contact_phone = phone (nusxa). Mavjud buyurtmalar
 * bo'sh qoladi — ko'rsatishda Telegram raqamiga qaytariladi (Order::contactPhone()).
 * Hech narsa o'chirilmaydi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('contact_phone', 32)->nullable()->after('phone');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('contact_phone', 32)->nullable()->after('note');
        });

        DB::statement('UPDATE users SET contact_phone = phone WHERE phone IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('contact_phone');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('contact_phone');
        });
    }
};
