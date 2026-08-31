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

class RestaurantApiTest extends TestCase
{
    use InteractsWithTelegramInitData;
    use RefreshDatabase;

    private User $user;

    private Address $address;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindInitDataValidator();

        // Chorshanba 12:00, Toshkent — barcha seed restoranlari ochiq bo'ladigan vaqt.
        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00', 'Asia/Tashkent'));

        $this->user = User::factory()->create(['telegram_id' => 555000]);
        // Toshkent markazi
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
        $day = [['00:00', '23:59']];

        return array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], $day);
    }

    private function restaurant(array $attrs = []): Restaurant
    {
        return Restaurant::factory()
            ->for(District::factory())
            ->create(array_replace(['work_hours' => $this->alwaysOpen(), 'is_open' => true], $attrs));
    }

    public function test_returns_only_restaurants_within_delivery_radius(): void
    {
        $near = $this->restaurant(['name' => 'Yaqin', 'lat' => 41.311, 'lng' => 69.281, 'delivery_radius_km' => 5]);
        $far = $this->restaurant(['name' => 'Uzoq', 'lat' => 41.700, 'lng' => 69.700, 'delivery_radius_km' => 5]);

        $this->getJson('/api/restaurants?address_id='.$this->address->id, $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Yaqin')
            ->assertJsonPath('data.0.id', $near->id);

        $this->assertStringNotContainsString('Uzoq', $this->getJson(
            '/api/restaurants?address_id='.$this->address->id, $this->headers()
        )->getContent());
        $this->assertNotContains($far->id, [$near->id]);
    }

    public function test_excludes_restaurants_with_is_open_false(): void
    {
        $this->restaurant(['name' => 'Ochiq', 'lat' => 41.311, 'lng' => 69.280]);
        $this->restaurant(['name' => 'Yopiq', 'lat' => 41.311, 'lng' => 69.280, 'is_open' => false]);

        $this->getJson('/api/restaurants?address_id='.$this->address->id, $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Ochiq');
    }

    public function test_excludes_restaurants_outside_work_hours(): void
    {
        // Hozir chorshanba 12:00 — bu restoran faqat 18:00 dan ochiq
        $this->restaurant([
            'name' => 'Kechqurun',
            'lat' => 41.311, 'lng' => 69.280,
            'work_hours' => ['wed' => [['18:00', '23:00']]],
        ]);
        $this->restaurant(['name' => 'Kunduzi', 'lat' => 41.311, 'lng' => 69.280]);

        $this->getJson('/api/restaurants?address_id='.$this->address->id, $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Kunduzi');
    }

    public function test_results_include_distance_and_are_sorted_by_it(): void
    {
        $this->restaurant(['name' => 'B', 'lat' => 41.320, 'lng' => 69.290, 'delivery_radius_km' => 10]);
        $this->restaurant(['name' => 'A', 'lat' => 41.311, 'lng' => 69.280, 'delivery_radius_km' => 10]);

        $data = $this->getJson('/api/restaurants?address_id='.$this->address->id, $this->headers())
            ->assertOk()
            ->json('data');

        $this->assertSame(['A', 'B'], array_column($data, 'name'));
        $this->assertIsNumeric($data[0]['distance_km']);
        $this->assertLessThan($data[1]['distance_km'], $data[0]['distance_km']);
    }

    public function test_address_of_another_user_is_rejected(): void
    {
        $other = Address::factory()->create(); // boshqa foydalanuvchi

        $this->getJson('/api/restaurants?address_id='.$other->id, $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('address_id');
    }

    public function test_include_closed_returns_closed_restaurants_last_with_flag(): void
    {
        $this->restaurant(['name' => 'Ochiq', 'lat' => 41.311, 'lng' => 69.280]);
        $this->restaurant(['name' => 'Yopiq', 'lat' => 41.311, 'lng' => 69.280, 'is_open' => false]);

        $data = $this->getJson(
            '/api/restaurants?address_id='.$this->address->id.'&include_closed=1',
            $this->headers(),
        )->assertOk()->json('data');

        $this->assertSame(['Ochiq', 'Yopiq'], array_column($data, 'name'));
        $this->assertTrue($data[0]['is_open_now']);
        $this->assertFalse($data[1]['is_open_now']);
    }

    public function test_include_closed_still_excludes_out_of_radius(): void
    {
        $this->restaurant(['name' => 'Uzoq', 'lat' => 41.700, 'lng' => 69.700, 'delivery_radius_km' => 3, 'is_open' => false]);

        $this->getJson(
            '/api/restaurants?address_id='.$this->address->id.'&include_closed=1',
            $this->headers(),
        )->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_show_returns_single_restaurant_without_distance(): void
    {
        $restaurant = $this->restaurant(['name' => 'Bitta']);

        $this->getJson("/api/restaurants/{$restaurant->id}", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.name', 'Bitta')
            ->assertJsonPath('data.is_open_now', true)
            ->assertJsonMissingPath('data.distance_km');
    }

    public function test_menu_returns_active_categories_with_available_products(): void
    {
        $restaurant = $this->restaurant(['lat' => 41.311, 'lng' => 69.280]);

        $visible = Category::factory()->for($restaurant)->create(['name' => 'Asosiy', 'sort_order' => 0, 'is_active' => true]);
        Category::factory()->for($restaurant)->create(['name' => 'Yashirin', 'is_active' => false]);

        Product::factory()->for($visible)->create(['name' => 'Osh', 'is_available' => true, 'sort_order' => 0]);
        Product::factory()->for($visible)->create(['name' => 'Tugagan', 'is_available' => false]);

        $this->getJson("/api/restaurants/{$restaurant->id}/menu", $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Asosiy')
            ->assertJsonCount(1, 'data.0.products')
            ->assertJsonPath('data.0.products.0.name', 'Osh');
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->getJson('/api/restaurants?address_id=1')->assertUnauthorized();
    }
}
