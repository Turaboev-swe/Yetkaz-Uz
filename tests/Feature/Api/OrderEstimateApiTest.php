<?php

namespace Tests\Feature\Api;

use App\Models\Address;
use App\Models\Category;
use App\Models\District;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Delivery\RestaurantFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTelegramInitData;
use Tests\TestCase;

/**
 * POST /api/orders/estimate — rasmiylashtirish ekrani. `delivery_fee` va
 * `distance_km` xuddi shu qiymatlar bilan buyurtma yaratiladi (bitta manba —
 * DeliveryFeeCalculator), shaffoflik uchun oldindan ko'rsatiladi.
 */
class OrderEstimateApiTest extends TestCase
{
    use InteractsWithTelegramInitData;
    use RefreshDatabase;

    private User $user;

    private Address $address;

    private Restaurant $restaurant;

    private Product $osh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindInitDataValidator();
        Http::fake();
        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00', 'Asia/Tashkent'));

        $this->user = User::factory()->create(['telegram_id' => 900901]);
        $this->address = Address::factory()->for($this->user)->default()->create(['lat' => 40.7830, 'lng' => 72.3500]);

        $always = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], [['00:00', '23:59']]);
        $this->restaurant = Restaurant::factory()->for(District::factory())->create([
            'name' => 'Test Resto', 'lat' => 40.7833, 'lng' => 72.3506,
            'is_open' => true, 'work_hours' => $always,
            'delivery_radius_km' => 8, 'delivery_fee' => 1_000_000,
            // Factory'ning random qiymati (0 / 30 000 / 50 000 so'm) savat summasidan (32 000 so'm,
            // bitta "osh") oshib ketsa test tasodifan flaky bo'lardi — shu sabab aniq 0 qilib qo'yamiz.
            'min_order_amount' => 0,
        ]);
        $cat = Category::factory()->for($this->restaurant)->create(['is_active' => true]);
        $this->osh = Product::factory()->for($cat)->create(['price' => 3_200_000, 'prep_time_min' => 20, 'is_available' => true]);
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

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'restaurant_id' => $this->restaurant->id,
            'delivery_type' => 'delivery',
            'address_id' => $this->address->id,
            'items' => [['product_id' => $this->osh->id, 'qty' => 1]],
        ], $overrides);
    }

    public function test_estimate_returns_distance_and_free_delivery_fee_within_radius(): void
    {
        $this->restaurant->update(['free_delivery_radius_km' => 1, 'price_per_km' => 300_000]);

        $this->postJson('/api/orders/estimate', $this->payload(), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.delivery_fee', 0);
    }

    public function test_estimate_matches_the_fee_the_order_will_actually_charge(): void
    {
        $this->restaurant->update(['free_delivery_radius_km' => 1, 'price_per_km' => 200_000]);
        $far = Address::factory()->for($this->user)->create(['lat' => 40.83, 'lng' => 72.40]);

        $estimate = $this->postJson('/api/orders/estimate', $this->payload(['address_id' => $far->id]), $this->headers())
            ->assertOk();

        $distanceKm = app(RestaurantFinder::class)->distanceKm($this->restaurant, $far);
        $expectedFee = (int) round($distanceKm * 200_000);

        $this->assertGreaterThan(1, $distanceKm);
        $estimate->assertJsonPath('data.delivery_fee', $expectedFee);
        $this->assertEqualsWithDelta($distanceKm, $estimate->json('data.distance_km'), 0.01);

        // Estimate — buyurtma yaratilganda chiqadigan bilan bir xil (bitta manba).
        $order = $this->postJson('/api/orders', $this->payload(['address_id' => $far->id]), $this->headers())
            ->assertCreated();
        $this->assertSame($expectedFee, $order->json('data.delivery_fee'));
    }

    public function test_pickup_estimate_has_no_fee_and_no_distance(): void
    {
        $this->restaurant->update(['free_delivery_radius_km' => 1, 'price_per_km' => 200_000]);

        $this->postJson('/api/orders/estimate', $this->payload([
            'delivery_type' => 'pickup',
            'address_id' => null,
        ]), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.delivery_fee', 0)
            ->assertJsonPath('data.distance_km', null);
    }

    public function test_estimate_without_distance_pricing_shows_the_flat_fee(): void
    {
        $this->postJson('/api/orders/estimate', $this->payload(), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.delivery_fee', 1_000_000);
    }
}
