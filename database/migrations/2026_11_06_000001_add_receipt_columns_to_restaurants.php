<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chek qatoridagi belgilar soni (receipt_columns): 32 | 42 | 48. NULL — qog'oz kengligidan
 * (receipt_paper_mm: 58 -> 32, 80 -> 42), shuning uchun mavjud restoranlar o'zgarmaydi.
 * 48 — 80 mm printer Font A'da 576 nuqta (masalan Xprinter XP-80T).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->unsignedSmallInteger('receipt_columns')->nullable()->after('receipt_paper_mm');
        });

        DB::statement('ALTER TABLE restaurants ADD CONSTRAINT restaurants_receipt_columns_check CHECK (receipt_columns IS NULL OR receipt_columns IN (32, 42, 48))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE restaurants DROP CONSTRAINT IF EXISTS restaurants_receipt_columns_check');

        Schema::table('restaurants', fn (Blueprint $table) => $table->dropColumn('receipt_columns'));
    }
};
