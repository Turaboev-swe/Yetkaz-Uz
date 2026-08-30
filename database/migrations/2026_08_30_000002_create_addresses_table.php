<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// PostGIS geografik indeks — lat/lng dan hisoblangan nuqta bo'yicha (funksional GIST).
// Alohida ustun saqlanmaydi, shu bilan Eloquent yozuvlari sodda qoladi.

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();          // "Uy", "Ish"
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('address_text');
            $table->string('entrance', 32)->nullable();
            $table->string('floor', 32)->nullable();
            $table->string('apartment', 32)->nullable();
            $table->string('note')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'is_default']);
        });

        DB::statement(<<<'SQL'
            CREATE INDEX addresses_geo_gist ON addresses
            USING GIST ((ST_SetSRID(ST_MakePoint(lng, lat), 4326)::geography))
        SQL);

        // Har foydalanuvchida faqat bitta is_default = true manzil bo'lishi mumkin.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX addresses_one_default_per_user
            ON addresses (user_id)
            WHERE is_default IS TRUE
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
