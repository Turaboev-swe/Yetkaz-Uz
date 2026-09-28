<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Models\Address;
use App\Models\Category;
use App\Models\District;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Delivery\RestaurantFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    // --- Masofaga qarab yetkazish narxi (free_delivery_radius_km / price_per_km) ---

    public function test_delivery_is_free_within_the_free_radius(): void
    {
        // Restoran va manzil bir-biriga juda yaqin (setUp) — masofa 1 km dan kam.
        $this->restaurant->update(['free_delivery_radius_km' => 1, 'price_per_km' => 300_000]);

        $this->postJson('/api/orders', $this->payload(), $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.delivery_fee', 0)
            ->assertJsonPath('data.total', 6_900_000); // faqat taomlar summasi
    }

    public function test_delivery_beyond_free_radius_charges_price_per_km_for_the_whole_distance(): void
    {
        $this->restaurant->update(['free_delivery_radius_km' => 1, 'price_per_km' => 200_000]); // 2000 so'm/km
        $far = Address::factory()->for($this->user)->create(['lat' => 40.83, 'lng' => 72.40]);

        $distanceKm = app(RestaurantFinder::class)->distanceKm($this->restaurant, $far);
        $expectedFee = (int) round($distanceKm * 200_000);

        $res = $this->postJson('/api/orders', $this->payload(['address_id' => $far->id]), $this->headers())
            ->assertCreated();

        $this->assertGreaterThan(1, $distanceKm); // haqiqatan ham bepul radiusdan tashqarida
        $this->assertSame($expectedFee, $res->json('data.delivery_fee'));
        $this->assertSame(6_900_000 + $expectedFee, $res->json('data.total'));
    }

    public function test_distance_pricing_unset_keeps_the_old_flat_fee(): void
    {
        // free_delivery_radius_km / price_per_km hech biri to'ldirilmagan (setUp'dagi holat) —
        // eski qat'iy delivery_fee o'zgarishsiz ishlaydi (regressiya yo'q).
        $this->assertNull($this->restaurant->free_delivery_radius_km);
        $this->assertNull($this->restaurant->price_per_km);

        $this->postJson('/api/orders', $this->payload(), $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.delivery_fee', 1_000_000);
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

    // --- Promokod ---

    public function test_a_valid_promo_code_reduces_the_total_and_is_recorded_on_the_order(): void
    {
        $promo = PromoCode::factory()->percent(20)->restaurantShare(50)->create(['code' => 'OSON50']);

        $res = $this->postJson('/api/orders', $this->payload(['promo_code' => 'oson50']), $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.subtotal', 6_900_000)
            ->assertJsonPath('data.discount_amount', 1_380_000) // 20% of 6.9M
            ->assertJsonPath('data.total', 6_520_000);           // 6.9M + 1M - 1.38M

        $this->assertDatabaseHas('orders', [
            'id' => $res->json('data.id'),
            'promo_code_id' => $promo->id,
            'discount_amount' => 1_380_000,
            'discount_restaurant_share' => 690_000,
            'discount_platform_share' => 690_000,
        ]);
    }

    public function test_discount_only_applies_to_the_food_subtotal_not_the_delivery_fee(): void
    {
        PromoCode::factory()->percent(100)->create(['code' => 'FREEFOOD']);

        $this->postJson('/api/orders', $this->payload(['promo_code' => 'FREEFOOD']), $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.discount_amount', 6_900_000) // butun subtotal, yetkazish emas
            ->assertJsonPath('data.total', 1_000_000);           // faqat yetkazish narxi qoladi
    }

    public function test_unknown_promo_code_rejects_the_whole_order(): void
    {
        $this->postJson('/api/orders', $this->payload(['promo_code' => 'YOQ-BUNDAY']), $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('promo_code');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_promo_code_restricted_to_another_restaurant_is_rejected(): void
    {
        $otherRestaurant = Restaurant::factory()->create();
        PromoCode::factory()->create(['code' => 'FAQATB', 'restaurant_id' => $otherRestaurant->id]);

        $this->postJson('/api/orders', $this->payload(['promo_code' => 'FAQATB']), $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('promo_code');
    }

    public function test_rejected_order_reports_the_specific_promo_reason(): void
    {
        PromoCode::factory()->create(['code' => 'ESKI', 'ends_at' => now()->subDay()]);

        $this->postJson('/api/orders', $this->payload(['promo_code' => 'ESKI']), $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('code', 'promo_code_invalid')
            ->assertJsonPath('promo_error', 'expired');
    }

    public function test_per_user_limit_is_enforced_when_placing_orders(): void
    {
        PromoCode::factory()->create(['code' => 'BIRMARTA', 'per_user_limit' => 1]);

        $this->postJson('/api/orders', $this->payload(['promo_code' => 'BIRMARTA']), $this->headers())->assertCreated();

        $this->postJson('/api/orders', $this->payload(['promo_code' => 'BIRMARTA']), $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('promo_error', 'user_limit_reached');

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_cancelling_an_order_frees_its_promo_slot(): void
    {
        PromoCode::factory()->create(['code' => 'BIRMARTA', 'per_user_limit' => 1]);
        $first = $this->postJson('/api/orders', $this->payload(['promo_code' => 'BIRMARTA']), $this->headers())
            ->assertCreated()
            ->json('data.id');

        Order::query()->whereKey($first)->update(['status' => OrderStatus::Cancelled->value]);

        $this->postJson('/api/orders', $this->payload(['promo_code' => 'BIRMARTA']), $this->headers())->assertCreated();
    }

    public function test_total_usage_limit_is_enforced_across_customers(): void
    {
        $promo = PromoCode::factory()->create(['code' => 'YAKKA', 'total_usage_limit' => 1]);
        Order::factory()->create(['promo_code_id' => $promo->id]); // boshqa mijoz ishlatgan

        $this->postJson('/api/orders', $this->payload(['promo_code' => 'YAKKA']), $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('promo_error', 'usage_limit_reached');
    }

    /**
     * Poyga holatiga qarshi: promokod qatori FOR UPDATE bilan qulflanadi va buyurtma
     * AYNAN shu tranzaksiyada yoziladi — ikkinchi so'rov qulfni birinchisi commit
     * qilguncha kutadi va limitni yangi buyurtma bilan birga sanaydi.
     */
    public function test_promo_row_is_locked_in_the_same_transaction_that_inserts_the_order(): void
    {
        PromoCode::factory()->create(['code' => 'OSON50']);
        $base = DB::transactionLevel();
        $lockLevel = null;
        $insertLevel = null;

        DB::listen(function ($query) use (&$lockLevel, &$insertLevel) {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'promo_codes') && str_contains($sql, 'for update')) {
                $lockLevel = DB::transactionLevel();
            }
            if (str_starts_with($sql, 'insert into "orders"')) {
                $insertLevel = DB::transactionLevel();
            }
        });

        $this->postJson('/api/orders', $this->payload(['promo_code' => 'OSON50']), $this->headers())->assertCreated();

        $this->assertNotNull($lockLevel, 'promo_codes qatori FOR UPDATE bilan qulflanmadi');
        $this->assertGreaterThan($base, $lockLevel, 'Qulf OrderService tranzaksiyasi ichida bo\'lishi kerak');
        $this->assertSame($lockLevel, $insertLevel, 'Qulf va buyurtma yozilishi bitta tranzaksiyada bo\'lishi kerak');
    }

    public function test_order_without_a_promo_code_has_zeroed_discount_fields(): void
    {
        $this->postJson('/api/orders', $this->payload(), $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.discount_amount', 0)
            ->assertJsonPath('data.total', 7_900_000);
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/orders', $this->payload())->assertUnauthorized();
    }

    public function test_rejects_order_when_user_has_no_phone(): void
    {
        // Mehmon (QR / deep-link) — telefon ulashmagan.
        $this->user->update(['phone' => null, 'full_name' => null, 'profile_completed' => false]);

        $this->postJson('/api/orders', $this->payload([
            'delivery_type' => 'pickup',
            'address_id' => null,
        ]), $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('code', 'phone_required');

        $this->assertDatabaseCount('orders', 0);
    }
}
