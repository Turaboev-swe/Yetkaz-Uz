<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        // lat/lng dan avtomatik hisoblanadigan PostGIS geography ustuni (restaurants bilan bir xil).
        DB::statement(<<<'SQL'
            ALTER TABLE addresses
                ADD COLUMN location geography(Point, 4326)
                GENERATED ALWAYS AS (ST_SetSRID(ST_MakePoint(lng, lat), 4326)::geography) STORED
        SQL);

        DB::statement('CREATE INDEX addresses_location_gist ON addresses USING GIST (location)');

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
