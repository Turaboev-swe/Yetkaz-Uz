<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Xodim telefon raqami — /kitchen "Yo'lga chiqdi" da kuryer tanlashда
 * ro'yxatда ko'rsatiladi va buyurtmaга snapshot qilib yoziladi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->string('phone', 32)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
