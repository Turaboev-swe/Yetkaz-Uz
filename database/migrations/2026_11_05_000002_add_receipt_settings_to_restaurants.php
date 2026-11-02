<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chek sozlamalari (restoran bo'yicha):
 *  - receipt_paper_mm: qog'oz kengligi, 58 yoki 80 (standart 80 -> 42 belgi; 58 -> 32 belgi)
 *  - receipt_codepage: ESC t n kodlash jadvali (standart 16 = WPC1252, Xprinter/Epson-mos)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->unsignedSmallInteger('receipt_paper_mm')->default(80)->after('printer_port');
            $table->unsignedSmallInteger('receipt_codepage')->default(16)->after('receipt_paper_mm');
        });

        DB::statement('ALTER TABLE restaurants ADD CONSTRAINT restaurants_receipt_paper_mm_check CHECK (receipt_paper_mm IN (58, 80))');
        DB::statement('ALTER TABLE restaurants ADD CONSTRAINT restaurants_receipt_codepage_check CHECK (receipt_codepage BETWEEN 0 AND 255)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE restaurants DROP CONSTRAINT IF EXISTS restaurants_receipt_paper_mm_check');
        DB::statement('ALTER TABLE restaurants DROP CONSTRAINT IF EXISTS restaurants_receipt_codepage_check');

        Schema::table('restaurants', fn (Blueprint $table) => $table->dropColumn(['receipt_paper_mm', 'receipt_codepage']));
    }
};
