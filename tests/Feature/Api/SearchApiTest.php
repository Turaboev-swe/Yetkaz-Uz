<?php

namespace Tests\Feature\Api;

use App\Models\Address;
use App\Models\Category;
use App\Models\District;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithTelegramInitData;
use Tests\TestCase;

class SearchApiTest extends TestCase
{
    use InteractsWithTelegramInitData;
    use RefreshDatabase;

    private User $user;

    private Address $address;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindInitDataValidator();
        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00', 'Asia/Tashkent'));

        $this->user = User::factory()->create(['telegram_id' => 777000]);
        $this->address = Address::factory()->for($this->user)->default()->create([
            'lat' => 41.311, 'lng' => 69.279,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function headers(): array
    {
        return $this->initDataHeaders($this->signedInitData(['id' => $this->user->telegram_id]));
    }

    private function alwaysOpen(): array
    {
        return array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], [['00:00', '23:59']]);
    }

    private function dish(string $restaurantName, float $lat, float $lng, string $dish, int $price): Product
    {
        $restaurant = Restaurant::factory()->for(District::factory())->create([
            'name' => $restaurantName,
            'lat' => $lat, 'lng' => $lng,
            'delivery_radius_km' => 8,
            'is_open' => true,
            'work_hours' => $this->alwaysOpen(),
        ]);
        $category = Category::factory()->for($restaurant)->create(['is_active' => true]);

        return Product::factory()->for($category)->create([
            'name' => $dish, 'price' => $price, 'is_available' => true,
        ]);
    }

    public function test_finds_a_dish_across_multiple_delivering_restaurants(): void
    {
        $this->dish('Milliy', 41.311, 69.280, "Lag'mon", 2_800_000);
        $this->dish('Osh Markazi', 41.315, 69.285, "Tovuqli lag'mon", 3_100_000);
        $this->dish('Boshqa', 41.311, 69.281, 'Burger', 2_500_000);

        $data = $this->getJson('/api/search?q=lagmon&address_id='.$this->address->id, $this->headers())
            ->assertOk()
            ->json('data');

        $names = array_column(array_column($data, 'product'), 'name');
        $this->assertContains("Lag'mon", $names);
        $this->assertContains("Tovuqli lag'mon", $names);
        $this->assertNotContains('Burger', $names);

        $this->assertArrayHasKey('restaurant', $data[0]);
        $this->assertArrayHasKey('name', $data[0]['restaurant']);
        $this->assertArrayHasKey('price', $data[0]['product']);
        $this->assertIsNumeric($data[0]['distance_km']);
    }

    public function test_search_respects_delivery_radius(): void
    {
        $this->dish('Yaqin', 41.311, 69.280, "Lag'mon", 2_800_000);
        $this->dish('Uzoq', 41.750, 69.750, "Lag'mon", 2_600_000);

        $data = $this->getJson('/api/search?q=lagmon&address_id='.$this->address->id, $this->headers())
            ->assertOk()
            ->json('data');

        $restaurants = array_column(array_column($data, 'restaurant'), 'name');
        $this->assertContains('Yaqin', $restaurants);
        $this->assertNotContains('Uzoq', $restaurants);
    }

    public function test_search_excludes_closed_restaurants(): void
    {
        $this->dish('Ochiq', 41.311, 69.280, "Lag'mon", 2_800_000);

        $closed = Restaurant::factory()->for(District::factory())->create([
            'name' => 'Yopiq', 'lat' => 41.311, 'lng' => 69.281,
            'delivery_radius_km' => 8, 'is_open' => false, 'work_hours' => $this->alwaysOpen(),
        ]);
        $cat = Category::factory()->for($closed)->create(['is_active' => true]);
        Product::factory()->for($cat)->create(['name' => "Lag'mon", 'is_available' => true]);

        $data = $this->getJson('/api/search?q=lagmon&address_id='.$this->address->id, $this->headers())
            ->assertOk()
            ->json('data');

        $this->assertSame(['Ochiq'], array_values(array_unique(
            array_column(array_column($data, 'restaurant'), 'name')
        )));
    }

    public function test_query_must_be_at_least_two_characters(): void
    {
        $this->getJson('/api/search?q=a&address_id='.$this->address->id, $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('q');
    }

    public function test_search_requires_authentication(): void
    {
        $this->getJson('/api/search?q=osh&address_id=1')->assertUnauthorized();
    }
}
