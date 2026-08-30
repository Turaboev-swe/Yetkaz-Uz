<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('city_id')->constrained()->restrictOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('phone', 32)->nullable();
            $table->string('logo_url')->nullable();

            $table->unsignedSmallInteger('avg_prep_time_min')->default(20);
            $table->decimal('delivery_radius_km', 5, 2)->default(5);
            $table->unsignedBigInteger('min_order_amount')->default(0);   // tiyin
            $table->unsignedBigInteger('delivery_fee')->default(0);       // tiyin

            $table->boolean('is_open')->default(true);
            $table->jsonb('work_hours')->nullable();  // {"mon": [["09:00","23:00"]], ...}

            // jowi | poster | iiko | escpos | manual
            $table->string('pos_type', 16)->default('manual');
            $table->string('printer_host')->nullable();       // escpos: 192.168.x.x
            $table->unsignedSmallInteger('printer_port')->default(9100);
            $table->text('pos_credentials')->nullable();      // shifrlangan (encrypted cast)

            $table->timestamps();

            $table->index('city_id');
            $table->index('is_open');
            $table->index(['city_id', 'is_open']);
            $table->index('pos_type');
        });

        DB::statement("ALTER TABLE restaurants ADD CONSTRAINT restaurants_pos_type_check CHECK (pos_type IN ('jowi','poster','iiko','escpos','manual'))");

        // lat/lng dan hisoblangan geografik nuqta bo'yicha funksional GIST indeks
        // (ST_DWithin bilan yetkazish radiusidagi restoranlarni tez topish uchun).
        DB::statement(<<<'SQL'
            CREATE INDEX restaurants_geo_gist ON restaurants
            USING GIST ((ST_SetSRID(ST_MakePoint(lng, lat), 4326)::geography))
        SQL);

        // Umumiy taom qidiruvida restoran nomini ham chiqarish uchun trigram indeks.
        DB::statement('CREATE INDEX restaurants_name_trgm ON restaurants USING GIN (name gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurants');
    }
};
