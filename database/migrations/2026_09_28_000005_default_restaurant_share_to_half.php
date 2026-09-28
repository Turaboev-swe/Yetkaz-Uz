<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * QAROR (yangilangan): restaurant_share_percent standart qiymati 50 — chegirmaning
 * yarmini restoran, yarmini platforma qoplaydi. Admin har kod uchun boshqa ulush
 * bera oladi. (000004 standartni 0 qilgan edi — bu uni 50 ga qaytaradi; mavjud
 * kodlar va buyurtma snapshot'lari o'zgarmaydi, faqat yangi kodlar standarti.)
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE promo_codes ALTER COLUMN restaurant_share_percent SET DEFAULT 50');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE promo_codes ALTER COLUMN restaurant_share_percent SET DEFAULT 0');
    }
};
