<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Taom narxi har o'zgarganda yoziladi (Product observer). Narxlar tiyinda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // O'zgartirgan xodim. Seeder / tizim o'zgartirса null bo'lishi mumkin.
            $table->foreignId('staff_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('old_price'); // tiyin
            $table->unsignedBigInteger('new_price'); // tiyin
            $table->timestamp('changed_at')->useCurrent();

            $table->index(['product_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_history');
    }
};
