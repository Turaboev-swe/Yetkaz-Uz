<?php

namespace Tests\Feature\Api;

use App\Models\Address;
use App\Models\Category;
use App\Models\District;
use App\Models\Product;
use App\Models\Region;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithTelegramInitData;
use Tests\TestCase;

class GeoApiTest extends TestCase
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

        $this->user = User::factory()->create(['telegram_id' => 909090]);
        $this->address = Address::factory()->for($this->user)->default()->create([
            'lat' => 40.7821, 'lng' => 72.3442, // Andijon
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

    public function test_regions_endpoint_returns_regions_with_districts(): void
    {
        $region = Region::factory()->create(['name' => 'Andijon viloyati', 'code' => 'AN']);
        District::factory()->for($region)->create(['name' => 'Asaka tumani']);
        District::factory()->for($region)->create(['name' => 'Marhamat tumani']);

        $this->getJson('/api/regions', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.0.code', 'AN')
            ->assertJsonCount(2, 'data.0.districts');
    }

    public function test_districts_endpoint_filters_by_region(): void
    {
        $an = Region::factory()->create(['code' => 'AN']);
        $fa = Region::factory()->create(['code' => 'FA']);
        District::factory()->for($an)->create(['name' => 'Asaka']);
        District::factory()->for($fa)->create(['name' => "Farg'ona"]);

        $this->getJson("/api/districts?region_id={$an->id}", $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Asaka');
    }

    public function test_restaurants_can_be_filtered_by_district(): void
    {
        $d1 = District::factory()->create(['name' => 'Tuman 1']);
        $d2 = District::factory()->create(['name' => 'Tuman 2']);

        $r1 = Restaurant::factory()->for($d1)->create([
            'name' => 'D1 restoran', 'lat' => 40.7825, 'lng' => 72.3445,
            'delivery_radius_km' => 10, 'is_open' => true, 'work_hours' => $this->alwaysOpen(),
        ]);
        $r2 = Restaurant::factory()->for($d2)->create([
            'name' => 'D2 restoran', 'lat' => 40.7830, 'lng' => 72.3450,
            'delivery_radius_km' => 10, 'is_open' => true, 'work_hours' => $this->alwaysOpen(),
        ]);

        // filtrsiz — ikkalasi ham
        $this->getJson('/api/restaurants?address_id='.$this->address->id, $this->headers())
            ->assertOk()->assertJsonCount(2, 'data');

        // tuman filtri bilan — faqat bittasi
        $this->getJson("/api/restaurants?address_id={$this->address->id}&district_id={$d1->id}", $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'D1 restoran')
            ->assertJsonPath('data.0.district.name', 'Tuman 1');
    }

    public function test_district_does_not_affect_distance_calculation(): void
    {
        // Restoran tuman markazidan uzoqda, lekin manzil radiusida —
        // masofa lat/lng dan, tuman markazidan emas.
        $district = District::factory()->create([
            'name' => 'Katta tuman',
            'center_lat' => 40.9000, 'center_lng' => 72.9000, // manzildan ~50 km
        ]);
        $restaurant = Restaurant::factory()->for($district)->create([
            'name' => 'Yaqin restoran',
            'lat' => 40.7825, 'lng' => 72.3445, // manzilга ~50 m
            'delivery_radius_km' => 3,
            'is_open' => true, 'work_hours' => $this->alwaysOpen(),
        ]);

        $data = $this->getJson('/api/restaurants?address_id='.$this->address->id, $this->headers())
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('Yaqin restoran', $data[0]['name']);
        $this->assertLessThan(1, $data[0]['distance_km']); // ~0.05 km, tuman markazidagi 50 km emas
    }

    public function test_search_still_works_across_districts(): void
    {
        $d1 = District::factory()->create();
        $d2 = District::factory()->create();
        foreach ([[$d1, 'A oshxona'], [$d2, 'B oshxona']] as [$d, $name]) {
            $r = Restaurant::factory()->for($d)->create([
                'name' => $name, 'lat' => 40.7825, 'lng' => 72.3445,
                'delivery_radius_km' => 10, 'is_open' => true, 'work_hours' => $this->alwaysOpen(),
            ]);
            $c = Category::factory()->for($r)->create(['is_active' => true]);
            Product::factory()->for($c)->create(['name' => 'Osh', 'is_available' => true]);
        }

        $this->getJson('/api/search?q=osh&address_id='.$this->address->id, $this->headers())
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
