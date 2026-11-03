<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `restaurants.pos_type` ga yangi qiymat: `browser` (oshxona panelidan brauzer orqali chop etish).
 * Faqat CHECK cheklovi kengayadi — mavjud ma'lumotga tegilmaydi.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE restaurants DROP CONSTRAINT IF EXISTS restaurants_pos_type_check');
        DB::statement("ALTER TABLE restaurants ADD CONSTRAINT restaurants_pos_type_check CHECK (pos_type IN ('jowi','poster','iiko','escpos','manual','browser'))");
    }

    public function down(): void
    {
        // Brauzer rejimidagi restoranlar eski cheklovga sig'maydi — qo'lda (panel) rejimiga qaytariladi.
        DB::table('restaurants')->where('pos_type', 'browser')->update(['pos_type' => 'manual']);

        DB::statement('ALTER TABLE restaurants DROP CONSTRAINT IF EXISTS restaurants_pos_type_check');
        DB::statement("ALTER TABLE restaurants ADD CONSTRAINT restaurants_pos_type_check CHECK (pos_type IN ('jowi','poster','iiko','escpos','manual'))");
    }
};
