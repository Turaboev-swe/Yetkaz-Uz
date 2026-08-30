<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price'); // tiyin
            $table->string('photo_url')->nullable();
            $table->unsignedSmallInteger('prep_time_min')->default(15);
            $table->boolean('is_available')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['category_id', 'is_available', 'sort_order']);
        });

        // Umumiy taom qidiruvi ("lag'mon") — pg_trgm GIN indeks.
        DB::statement('CREATE INDEX products_name_trgm ON products USING GIN (name gin_trgm_ops)');
        // Diakritikasiz qidiruv uchun f_unaccent(name) bo'yicha ham indeks.
        DB::statement('CREATE INDEX products_name_unaccent_trgm ON products USING GIN (f_unaccent(name) gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
