<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Address;
use App\Models\Category;
use App\Models\District;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 1-bosqich: migratsiyalar, foreign key'lar, indekslar va model bog'lanishlari.
 */
class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_postgis_and_trgm_extensions_are_enabled(): void
    {
        $extensions = DB::table('pg_extension')->pluck('extname')->all();

        $this->assertContains('postgis', $extensions);
        $this->assertContains('pg_trgm', $extensions);
        $this->assertContains('unaccent', $extensions);
    }

    public function test_core_tables_exist(): void
    {
        foreach ([
            'users', 'regions', 'districts', 'addresses', 'restaurants',
            'categories', 'products', 'orders', 'order_status_history',
            'staff', 'product_price_history',
        ] as $table) {
            $this->assertTrue(
                DB::getSchemaBuilder()->hasTable($table),
                "`{$table}` jadvali mavjud emas",
            );
        }
    }

    public function test_expected_indexes_exist(): void
    {
        $indexes = DB::table('pg_indexes')->pluck('indexname')->all();

        foreach ([
            'users_telegram_id_unique',
            'users_phone_index',
            'addresses_location_gist',
            'addresses_one_default_per_user',
            'restaurants_location_gist',
            'restaurants_name_trgm',
            'products_name_trgm',
            'products_name_unaccent_trgm',
            'orders_order_number_unique',
            'orders_status_index',
            'orders_restaurant_id_status_index',
        ] as $index) {
            $this->assertContains($index, $indexes, "`{$index}` indeksi topilmadi");
        }
    }

    public function test_geography_location_columns_are_generated(): void
    {
        foreach (['restaurants', 'addresses'] as $table) {
            $col = DB::selectOne(
                'SELECT is_generated, udt_name FROM information_schema.columns
                 WHERE table_name = ? AND column_name = ?',
                [$table, 'location'],
            );

            $this->assertNotNull($col, "`{$table}.location` ustuni yo'q");
            $this->assertSame('ALWAYS', $col->is_generated);
            $this->assertSame('geography', $col->udt_name);
        }

        // lat/lng dan avtomatik to'ladi
        $r = Restaurant::factory()->create(['lat' => 41.3, 'lng' => 69.25]);
        $wkt = DB::selectOne(
            'SELECT ST_AsText(location::geometry) AS wkt FROM restaurants WHERE id = ?',
            [$r->id],
        )->wkt;
        $this->assertSame('POINT(69.25 41.3)', $wkt);
    }

    public function test_full_relationship_graph(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->for($user)->default()->create();
        $district = District::factory()->create();
        $restaurant = Restaurant::factory()->for($district)->create();
        $category = Category::factory()->for($restaurant)->create();
        $product = Product::factory()->for($category)->create(['name' => "Lag'mon", 'price' => 2_500_000]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'restaurant_id' => $restaurant->id,
            'address_id' => $address->id,
        ]);
        OrderStatusHistory::create([
            'order_id' => $order->id,
            'status' => OrderStatus::New->value,
            'changed_by' => 'system',
        ]);

        $this->assertTrue($user->addresses->contains($address));
        $this->assertSame($user->id, $address->user->id);
        $this->assertTrue($restaurant->categories->contains($category));
        $this->assertSame($restaurant->id, $product->restaurant()->id);
        $this->assertTrue($restaurant->products->contains('id', $product->id));
        $this->assertSame($restaurant->id, $order->restaurant->id);
        $this->assertSame(1, $order->statusHistory()->count());
        $this->assertSame(2_500_000, $product->fresh()->price);
    }

    public function test_foreign_keys_block_orphans(): void
    {
        $this->expectException(QueryException::class);

        Category::create(['restaurant_id' => 999999, 'name' => 'Orphan']);
    }

    public function test_restaurant_cannot_be_deleted_while_orders_exist(): void
    {
        $order = Order::factory()->create();

        $this->expectException(QueryException::class);

        $order->restaurant->delete();
    }

    public function test_one_default_address_per_user_is_enforced(): void
    {
        $user = User::factory()->create();
        Address::factory()->for($user)->default()->create();

        $this->expectException(QueryException::class);

        Address::factory()->for($user)->default()->create();
    }

    public function test_status_check_constraint_rejects_unknown_status(): void
    {
        $order = Order::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('orders')->where('id', $order->id)->update(['status' => 'teleported']);
    }

    public function test_money_is_stored_as_integer_tiyin(): void
    {
        $product = Product::factory()->create(['price' => 2_599_900]);

        $raw = DB::table('products')->where('id', $product->id)->value('price');

        $this->assertIsInt($product->fresh()->price);
        $this->assertSame(2599900, (int) $raw);
    }

    public function test_restaurant_radius_scope_uses_geography(): void
    {
        $district = District::factory()->create();

        // Toshkent markazi
        $near = Restaurant::factory()->for($district)->create([
            'lat' => 41.311, 'lng' => 69.279, 'delivery_radius_km' => 5,
        ]);
        // ~40 km uzoqlikda
        $far = Restaurant::factory()->for($district)->create([
            'lat' => 41.65, 'lng' => 69.60, 'delivery_radius_km' => 5,
        ]);

        $ids = Restaurant::query()
            ->deliversTo(41.311, 69.281)
            ->pluck('id')
            ->all();

        $this->assertContains($near->id, $ids);
        $this->assertNotContains($far->id, $ids);
    }

    public function test_product_search_matches_by_trigram_similarity(): void
    {
        $category = Category::factory()->create();
        Product::factory()->for($category)->create(['name' => "Lag'mon"]);
        Product::factory()->for($category)->create(['name' => 'Osh']);

        $names = Product::query()->search('lagmon')->pluck('name')->all();

        $this->assertContains("Lag'mon", $names);
        $this->assertNotContains('Osh', $names);
    }
}
