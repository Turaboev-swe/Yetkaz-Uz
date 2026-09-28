<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 000006: bitta promo_codes.restaurant_id -> promo_code_restaurant pivot.
 * Mavjud qiymat ko'chiriladi; NULL ("hammaga") kod barcha restoranlarga
 * KENGAYTIRILMAYDI (rozi bo'lmagan restoranga tushib qolmasin).
 *
 * Migratsiya down() -> eski ko'rinishda ma'lumot -> up(). Postgres'da DDL
 * tranzaksiyaviy — RefreshDatabase tranzaksiyasi bilan test oxirida qaytadi.
 */
class PromoCodeRestaurantPivotMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_09_28_000006_move_promo_code_restaurants_to_pivot.php');
    }

    /** Eski sxemadagi kod — restaurant_id NULL bo'lsa "barcha restoranlar" degani edi. */
    private function insertLegacyCode(string $code, ?int $restaurantId): int
    {
        return DB::table('promo_codes')->insertGetId([
            'code' => $code,
            'discount_type' => 'percent',
            'discount_value' => 10,
            'restaurant_id' => $restaurantId,
        ]);
    }

    public function test_existing_restaurant_is_copied_and_null_codes_are_not_opened_to_everyone(): void
    {
        $migration = $this->migration();
        $migration->down(); // eski sxema: promo_codes.restaurant_id, pivot yo'q

        $donix = Restaurant::factory()->create();
        Restaurant::factory()->count(2)->create(); // "hammaga" kod bularga TUSHMASLIGI kerak
        $scoped = $this->insertLegacyCode('FAQATDONIX', $donix->id);
        $global = $this->insertLegacyCode('HAMMAGA', null);

        $migration->up();

        $this->assertFalse(Schema::hasColumn('promo_codes', 'restaurant_id'));
        $this->assertSame(
            [$donix->id],
            DB::table('promo_code_restaurant')->where('promo_code_id', $scoped)->pluck('restaurant_id')->all(),
        );
        $this->assertSame(0, DB::table('promo_code_restaurant')->where('promo_code_id', $global)->count());
    }

    public function test_down_restores_a_single_restaurant_column(): void
    {
        $restaurant = Restaurant::factory()->create();
        $codeId = DB::table('promo_codes')->insertGetId([
            'code' => 'QAYTAR', 'discount_type' => 'percent', 'discount_value' => 10,
        ]);
        DB::table('promo_code_restaurant')->insert(['promo_code_id' => $codeId, 'restaurant_id' => $restaurant->id]);

        $this->migration()->down();

        $this->assertSame($restaurant->id, DB::table('promo_codes')->where('id', $codeId)->value('restaurant_id'));
        $this->assertFalse(Schema::hasTable('promo_code_restaurant'));
    }
}
