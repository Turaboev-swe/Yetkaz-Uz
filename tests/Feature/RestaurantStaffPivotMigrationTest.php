<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * restaurant_staff pivot: mavjud staff.restaurant_id qiymatlari ko'chiriladi,
 * ustunning o'zi QOLADI. platform_admin (restaurant_id NULL) — pivot'siz.
 *
 * Migratsiya down() -> eski ko'rinishda xodimlar -> up(). Postgres'da DDL
 * tranzaksiyaviy — RefreshDatabase tranzaksiyasi bilan test oxirida qaytadi.
 */
class RestaurantStaffPivotMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_09_30_000001_create_restaurant_staff_table.php');
    }

    /** Eski sxemada — to'g'ridan-to'g'ri jadvalga (model hodisalarisiz, pivot hali yo'q). */
    private function insertLegacyStaff(string $role, ?int $restaurantId): int
    {
        return DB::table('staff')->insertGetId([
            'name' => $role,
            'email' => uniqid($role).'@example.com',
            'password' => 'x',
            'role' => $role,
            'restaurant_id' => $restaurantId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_existing_staff_restaurants_are_copied_and_the_column_stays(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->assertFalse(Schema::hasTable('restaurant_staff'));

        $sushi = Restaurant::factory()->create();
        $vanilla = Restaurant::factory()->create();
        $cook = $this->insertLegacyStaff('kitchen_staff', $sushi->id);
        $owner = $this->insertLegacyStaff('restaurant_owner', $vanilla->id);
        $admin = $this->insertLegacyStaff('platform_admin', null);

        $migration->up();

        $this->assertTrue(Schema::hasColumn('staff', 'restaurant_id'));
        $pairs = DB::table('restaurant_staff')->orderBy('staff_id')->get(['staff_id', 'restaurant_id'])
            ->map(fn ($r) => [(int) $r->staff_id, (int) $r->restaurant_id])->all();
        $this->assertSame([[$cook, $sushi->id], [$owner, $vanilla->id]], $pairs);
        $this->assertSame(0, DB::table('restaurant_staff')->where('staff_id', $admin)->count());
    }

    public function test_pair_is_unique(): void
    {
        $restaurant = Restaurant::factory()->create();
        $staffId = $this->insertLegacyStaff('kitchen_staff', $restaurant->id);
        DB::table('restaurant_staff')->insert(['staff_id' => $staffId, 'restaurant_id' => $restaurant->id]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        DB::table('restaurant_staff')->insert(['staff_id' => $staffId, 'restaurant_id' => $restaurant->id]);
    }
}
