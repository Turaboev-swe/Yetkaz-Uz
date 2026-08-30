<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL kengaytmalari.
 *
 * - postgis      : geografik nuqtalar va masofa bo'yicha qidiruv (ST_DWithin)
 * - pg_trgm      : umumiy taom qidiruvi uchun trigram (LIKE / % o'xshashlik) indeksi
 * - unaccent     : diakritikasiz qidiruv (lag'mon / lagmon)
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');

        // unaccent() o'zi IMMUTABLE emas, shuning uchun indeks ifodasida ishlatib
        // bo'lmaydi. Immutable o'ram funksiya yaratamiz.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION f_unaccent(text)
            RETURNS text
            LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
            AS $$ SELECT public.unaccent('public.unaccent', $1) $$
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS f_unaccent(text)');
        DB::statement('DROP EXTENSION IF EXISTS unaccent');
        DB::statement('DROP EXTENSION IF EXISTS pg_trgm');
        DB::statement('DROP EXTENSION IF EXISTS postgis');
    }
};
