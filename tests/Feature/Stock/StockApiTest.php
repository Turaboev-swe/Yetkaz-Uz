<?php

namespace Tests\Feature\Stock;

use App\Models\Address;
use App\Models\Category;
use App\Models\District;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithTelegramInitData;
use Tests\TestCase;

/** Mini App API: menyuda qoldiq (stock / sold_out / low_stock), qidiruvda tugagan taom chiqmaydi. */
class StockApiTest extends TestCase
{
    use InteractsWithTelegramInitData;
    use RefreshDatabase;

    private User $user;

    private Address $address;

    private Restaurant $restaurant;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindInitDataValidator();
        Carbon::setTestNow(Carbon::parse('2026-11-03 12:00', 'Asia/Tashkent'));

        $this->user = User::factory()->create(['telegram_id' => 940001]);
        $this->address = Address::factory()->for($this->user)->default()->create(['lat' => 41.311, 'lng' => 69.279]);

        $always = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], [['00:00', '23:59']]);
        $this->restaurant = Restaurant::factory()->for(District::factory())->create([
            'lat' => 41.311, 'lng' => 69.279, 'delivery_radius_km' => 8, 'is_open' => true, 'work_hours' => $always,
        ]);
        $this->category = Category::factory()->for($this->restaurant)->create(['is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->initDataHeaders($this->signedInitData(['id' => $this->user->telegram_id]));
    }

    private function product(?int $stock, string $name = 'Osh', array $extra = []): Product
    {
        return Product::factory()->for($this->category)->create(array_replace(['name' => $name, 'stock_quantity' => $stock], $extra));
    }

    /** @return array<int, array<string, mixed>> menyudagi taomlar id bo'yicha */
    private function menu(): array
    {
        $rows = $this->getJson("/api/restaurants/{$this->restaurant->id}/menu", $this->headers())->assertOk()->json('data');

        return collect($rows)->flatMap(fn ($c) => $c['products'])->keyBy('id')->all();
    }

    // --- Menyu -----------------------------------------------------------------------------------

    public function test_a_sold_out_product_stays_in_the_menu_flagged_as_sold_out(): void
    {
        $tugagan = $this->product(0, 'Tugagan');

        $item = $this->menu()[$tugagan->id];

        $this->assertTrue($item['sold_out']);
        $this->assertSame(0, $item['stock']);
        $this->assertFalse($item['low_stock'], '0 uchun "Faqat N ta" emas — "Tugadi"');
    }

    public function test_unlimited_products_expose_no_stock_and_are_never_sold_out_or_low(): void
    {
        $cheksiz = $this->product(null, 'Cheksiz');

        $item = $this->menu()[$cheksiz->id];

        $this->assertNull($item['stock']);
        $this->assertFalse($item['sold_out']);
        $this->assertFalse($item['low_stock']);
    }

    /** @return array<string, array{0: ?int, 1: int, 2: bool}> */
    public static function thresholds(): array
    {
        return [
            'standart 5: 5 ta — ko‘rsatiladi' => [5, 5, true],
            'standart 5: 1 ta — ko‘rsatiladi' => [5, 1, true],
            'standart 5: 6 ta — ko‘rsatilmaydi' => [5, 6, false],
            'threshold 2: 3 ta — ko‘rsatilmaydi' => [2, 3, false],
            'threshold 2: 2 ta — ko‘rsatiladi' => [2, 2, true],
            'threshold null: 500 ta ham doim ko‘rsatiladi' => [null, 500, true],
        ];
    }

    #[DataProvider('thresholds')]
    public function test_low_stock_follows_the_configured_threshold(?int $threshold, int $stock, bool $expected): void
    {
        config(['yetkaz.stock_display_threshold' => $threshold]);
        $osh = $this->product($stock);

        $item = $this->menu()[$osh->id];

        $this->assertSame($expected, $item['low_stock']);
        $this->assertSame($stock, $item['stock'], 'cart limiti uchun aniq son har doim bor');
    }

    public function test_the_default_threshold_is_five_when_nothing_is_configured(): void
    {
        $this->assertSame(5, config('yetkaz.stock_display_threshold'));
    }

    public function test_threshold_null_is_kept_as_null_not_replaced_by_the_default(): void
    {
        config(['yetkaz.stock_display_threshold' => null]);

        $this->assertNull(config('yetkaz.stock_display_threshold', 5));
        $this->assertTrue($this->product(9999)->showsLowStock());
        $this->assertFalse($this->product(null)->showsLowStock(), 'cheksiz — hech qachon');
        $this->assertFalse($this->product(0)->showsLowStock(), 'tugagan — "Tugadi", "Faqat N" emas');
    }

    public function test_hidden_by_the_restaurant_is_still_not_in_the_menu(): void
    {
        $yashirin = $this->product(10, 'Yashirin', ['is_available' => false]);
        $tugagan = $this->product(0, 'Tugagan');

        $menu = $this->menu();

        $this->assertArrayNotHasKey($yashirin->id, $menu, 'qo‘lda yashirish (is_available=false) o‘zgarmagan');
        $this->assertArrayHasKey($tugagan->id, $menu);
    }

    public function test_restocking_flips_the_product_back_without_touching_is_available(): void
    {
        $osh = $this->product(0);
        $this->assertTrue($this->menu()[$osh->id]['sold_out']);

        $osh->update(['stock_quantity' => 8]);

        $item = $this->menu()[$osh->id];
        $this->assertFalse($item['sold_out']);
        $this->assertSame(8, $item['stock']);
        $this->assertTrue($osh->fresh()->is_available, 'is_available hech qachon o‘zgarmagan');
    }

    public function test_existing_menu_fields_are_unchanged(): void
    {
        $osh = $this->product(null, 'Oddiy', ['price' => 3_200_000, 'old_price' => 4_000_000]);

        $item = $this->menu()[$osh->id];

        $this->assertSame('Oddiy', $item['name']);
        $this->assertSame(3_200_000, $item['price']);
        $this->assertSame(4_000_000, $item['old_price']);
        $this->assertArrayHasKey('photo_url', $item);
        $this->assertArrayHasKey('prep_time_min', $item);
    }

    // --- Qidiruv ---------------------------------------------------------------------------------------

    /** @return list<int> */
    private function searchIds(string $q): array
    {
        $rows = $this->getJson('/api/search?q='.$q.'&address_id='.$this->address->id, $this->headers())->assertOk()->json('data');

        return array_map(fn ($r) => $r['product']['id'], $rows);
    }

    public function test_search_hides_sold_out_dishes_but_keeps_limited_and_unlimited_ones(): void
    {
        $tugagan = $this->product(0, 'Lagmon tugagan');
        $oz = $this->product(2, 'Lagmon oz qolgan');
        $cheksiz = $this->product(null, 'Lagmon cheksiz');

        $ids = $this->searchIds('lagmon');

        $this->assertNotContains($tugagan->id, $ids);
        $this->assertContains($oz->id, $ids);
        $this->assertContains($cheksiz->id, $ids);
    }

    public function test_search_returns_a_restocked_dish_again(): void
    {
        $lagmon = $this->product(0, 'Lagmon');
        $this->assertNotContains($lagmon->id, $this->searchIds('lagmon'));

        $lagmon->update(['stock_quantity' => 3]);

        $this->assertContains($lagmon->id, $this->searchIds('lagmon'));
    }
}
