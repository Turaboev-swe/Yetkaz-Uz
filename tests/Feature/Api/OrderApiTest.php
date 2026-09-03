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
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTelegramInitData;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use InteractsWithTelegramInitData;
    use RefreshDatabase;

    private User $user;

    private Address $address;

    private Restaurant $restaurant;

    private Product $osh;

    private Product $choy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindInitDataValidator();
        Http::fake();
        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00', 'Asia/Tashkent'));

        $this->user = User::factory()->create(['telegram_id' => 900900]);
        $this->address = Address::factory()->for($this->user)->default()->create(['lat' => 40.7830, 'lng' => 72.3500]);

        $always = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], [['00:00', '23:59']]);
        $this->restaurant = Restaurant::factory()->for(District::factory())->create([
            'name' => 'Test Resto', 'lat' => 40.7833, 'lng' => 72.3506,
            'is_open' => true, 'work_hours' => $always,
            'delivery_radius_km' => 8, 'delivery_fee' => 1_000_000,
            'min_order_amount' => 3_000_000, 'avg_prep_time_min' => 20,
        ]);
        $cat = Category::factory()->for($this->restaurant)->create(['is_active' => true]);
        $this->osh = Product::factory()->for($cat)->create(['name' => 'Osh', 'price' => 3_200_000, 'prep_time_min' => 20, 'is_available' => true]);
        $this->choy = Product::factory()->for($cat)->create(['name' => 'Choy', 'price' => 500_000, 'prep_time_min' => 5, 'is_available' => true]);
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
            'payment_method' => 'cash',
            'note' => 'Qo‘ng‘iroqsiz',
            'items' => [
                ['product_id' => $this->osh->id, 'qty' => 2],
                ['product_id' => $this->choy->id, 'qty' => 1],
            ],
        ], $overrides);
    }

    public function test_creates_a_delivery_order_with_totals_and_snapshot(): void
    {
        $res = $this->postJson('/api/orders', $this->payload(), $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.delivery_type', 'delivery')
            ->assertJsonPath('data.subtotal', 6_900_000)   // 3.2M*2 + 0.5M
            ->assertJsonPath('data.delivery_fee', 1_000_000)
            ->assertJsonPath('data.total', 7_900_000)
            ->assertJsonPath('data.note', 'Qo‘ng‘iroqsiz')
            ->assertJsonPath('data.address_snapshot.label', $this->address->label);

        $this->assertMatchesRegularExpression('/^YT-\d{6}$/', $res->json('data.order_number'));
        $this->assertGreaterThan(0, $res->json('data.eta_minutes'));

        $this->assertDatabaseHas('orders', ['user_id' => $this->user->id, 'total' => 7_900_000]);
        $this->assertDatabaseHas('order_status_history', ['status' => 'new', 'changed_by' => "user:{$this->user->id}"]);
    }

    public function test_price_is_taken_from_db_not_from_client(): void
    {
        $res = $this->postJson('/api/orders', $this->payload([
            'items' => [['product_id' => $this->osh->id, 'qty' => 1, 'price' => 1]],
        ]), $this->headers())->assertCreated();

        $this->assertSame(3_200_000, $res->json('data.items.0.price'));
        $this->assertSame(3_200_000, $res->json('data.subtotal'));
    }

    public function test_rejects_order_below_minimum(): void
    {
        $this->postJson('/api/orders', $this->payload([
            'items' => [['product_id' => $this->choy->id, 'qty' => 1]], // 5000 so'm < 30000
        ]), $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('items');
    }

    public function test_rejects_delivery_outside_radius(): void
    {
        $far = Address::factory()->for($this->user)->create(['lat' => 41.9, 'lng' => 72.9]);

        $this->postJson('/api/orders', $this->payload(['address_id' => $far->id]), $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('address_id');
    }

    public function test_rejects_unavailable_product(): void
    {
        $this->osh->update(['is_available' => false]);

        $this->postJson('/api/orders', $this->payload(), $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('items');
    }

    public function test_pickup_order_has_no_delivery_fee_and_no_address(): void
    {
        $res = $this->postJson('/api/orders', $this->payload([
            'delivery_type' => 'pickup',
            'address_id' => null,
        ]), $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.delivery_type', 'pickup')
            ->assertJsonPath('data.delivery_fee', 0)
            ->assertJsonPath('data.total', 6_900_000)
            ->assertJsonPath('data.address_snapshot', null);

        // Pickup ETA = pishirish(20) + navbat(0) + bufer(5) = 25; kuryer/yo'l = 0
        $this->assertSame(25, $res->json('data.eta_minutes'));
    }

    public function test_delivery_requires_address_id(): void
    {
        $this->postJson('/api/orders', $this->payload(['address_id' => null]), $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('address_id');
    }

    public function test_rejects_another_users_address(): void
    {
        $other = Address::factory()->create();

        $this->postJson('/api/orders', $this->payload(['address_id' => $other->id]), $this->headers())
            ->assertStatus(404);
    }

    public function test_show_returns_own_order_only(): void
    {
        $id = $this->postJson('/api/orders', $this->payload(), $this->headers())->json('data.id');

        $this->getJson("/api/orders/{$id}", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.restaurant.name', 'Test Resto');

        $stranger = User::factory()->create(['telegram_id' => 111333]);
        $this->getJson("/api/orders/{$id}", $this->initDataHeaders($this->signedInitData(['id' => 111333])))
            ->assertStatus(404);
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/orders', $this->payload())->assertUnauthorized();
    }
}
