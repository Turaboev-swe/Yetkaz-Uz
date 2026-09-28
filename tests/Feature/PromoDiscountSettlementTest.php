<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Filament\Admin\Resources\UserResource;
use App\Models\Address;
use App\Models\Category;
use App\Models\District;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Delivery\RestaurantFinder;
use App\Services\Reporting\OrderStatsService;
use App\Services\Reporting\ReportPeriod;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithTelegramInitData;
use Tests\TestCase;

/**
 * Chegirma taqsimoti — QAROR bo'yicha end-to-end (haqiqiy API + OrderService):
 *
 *   - admin kod uchun restoran qoplaydigan ulushni belgilaydi, qolganini platforma;
 *   - taqsimot buyurtmaga SNAPSHOT qilinadi, kod keyin o'zgarsa eski buyurtma o'zgarmaydi;
 *   - Daromad = mijoz to'lagan + platforma qoplagan; "Jami xarid" = mijoz to'lagan;
 *   - platforma qarzi = discount_platform_amount yig'indisi;
 *   - Mini App ko'rsatgan jami == buyurtma total (butun so'm).
 */
class PromoDiscountSettlementTest extends TestCase
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
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00', 'Asia/Tashkent'));

        $this->user = User::factory()->create(['telegram_id' => 800800]);
        $this->address = Address::factory()->for($this->user)->default()->create(['lat' => 40.7830, 'lng' => 72.3500]);

        $always = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], [['00:00', '23:59']]);
        $this->restaurant = Restaurant::factory()->for(District::factory())->create([
            'name' => 'Hisob Resto', 'lat' => 40.7833, 'lng' => 72.3506,
            'is_open' => true, 'work_hours' => $always,
            'delivery_radius_km' => 10, 'delivery_fee' => 10_000_00, 'min_order_amount' => 0,
        ]);
        $cat = Category::factory()->for($this->restaurant)->create(['is_active' => true]);
        $this->osh = Product::factory()->for($cat)->create(['name' => 'Osh', 'price' => 32_000_00, 'is_available' => true]);
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

    /** Osh × 2 = 64 000 so'm, yetkazish 10 000 so'm. */
    private function placeOrder(?string $code, int $qty = 2): Order
    {
        $id = $this->postJson('/api/orders', [
            'restaurant_id' => $this->restaurant->id,
            'delivery_type' => 'delivery',
            'address_id' => $this->address->id,
            'items' => [['product_id' => $this->osh->id, 'qty' => $qty]],
            'promo_code' => $code,
        ], $this->headers())->assertCreated()->json('data.id');

        return Order::query()->findOrFail($id);
    }

    private function deliver(Order $order): Order
    {
        $order->update(['status' => OrderStatus::Delivered, 'delivered_at' => now()]);

        return $order->fresh();
    }

    private function period(): ReportPeriod
    {
        return ReportPeriod::custom('2026-09-01', '2026-09-30');
    }

    private function stats(): OrderStatsService
    {
        return app(OrderStatsService::class);
    }

    private function customerSpent(): int
    {
        return (int) UserResource::getEloquentQuery()->findOrFail($this->user->id)->delivered_total_tiyin;
    }

    // --- 0% / 50% / 100%: daromad va platforma qarzi ------------------------

    /** @return array<string, array{int, int, int, int}> ulush => [restoran, platforma, daromad] */
    public static function shares(): array
    {
        // Chegirma 25% × 64 000 = 16 000 so'm; mijoz 64 000 + 10 000 − 16 000 = 58 000 to'laydi.
        return [
            '0% — hammasini platforma' => [0, 0, 16_000_00, 74_000_00],   // daromad = chegirmasiz summa
            '50%' => [50, 8_000_00, 8_000_00, 66_000_00],
            '100% — hammasini restoran' => [100, 16_000_00, 0, 58_000_00],
        ];
    }

    #[DataProvider('shares')]
    public function test_revenue_and_platform_debt_for_each_restaurant_share(
        int $percent, int $restaurantAmount, int $platformAmount, int $revenue,
    ): void {
        PromoCode::factory()->at($this->restaurant)->percent(25)->restaurantShare($percent)->create(['code' => 'CHEGIRMA']);

        $order = $this->deliver($this->placeOrder('CHEGIRMA'));

        // Snapshot buyurtmada
        $this->assertSame(16_000_00, $order->discount_amount);
        $this->assertSame($restaurantAmount, $order->discount_restaurant_amount);
        $this->assertSame($platformAmount, $order->discount_platform_amount);
        $this->assertSame(58_000_00, $order->total);

        // Daromad = mijoz to'lagan + platforma qoplagan
        $this->assertSame($revenue, $this->stats()->summary($this->period(), $this->restaurant->id)['revenue_tiyin']);

        // Platforma qarzi
        $debt = $this->stats()->platformDiscounts($this->period())->firstWhere('restaurant_id', $this->restaurant->id);
        $this->assertSame($platformAmount, $debt['platform_amount_tiyin']);
        $this->assertSame($restaurantAmount, $debt['restaurant_amount_tiyin']);

        // Mijozning "Jami xarid" — haqiqatda to'lagani, ulushdan qat'i nazar
        $this->assertSame(58_000_00, $this->customerSpent());
    }

    /** Standart (bazada) 50% — yarmini restoran, yarmini platforma. */
    public function test_new_codes_default_to_splitting_the_discount_in_half(): void
    {
        $promo = PromoCode::query()->create([
            'code' => 'STANDART', 'discount_type' => 'percent', 'discount_value' => 25,
        ])->fresh();
        $promo->restaurants()->attach($this->restaurant);

        $this->assertSame(50, $promo->restaurant_share_percent);
        $this->assertSame(1, $promo->per_user_limit); // baza standarti — har mijoz bir marta

        $order = $this->deliver($this->placeOrder('STANDART'));
        $this->assertSame(8_000_00, $order->discount_restaurant_amount);
        $this->assertSame(8_000_00, $order->discount_platform_amount);

        // Hisobotda: platforma qarzi 8 000, daromad 58 000 + 8 000 = 66 000.
        $this->assertSame(8_000_00, $this->stats()->platformDiscounts($this->period())[0]['platform_amount_tiyin']);
        $this->assertSame(66_000_00, $this->stats()->summary($this->period(), $this->restaurant->id)['revenue_tiyin']);
    }

    // --- Snapshot: kod keyin o'zgarsa eski buyurtma o'zgarmaydi -------------

    public function test_changing_the_code_later_does_not_change_existing_orders_or_reports(): void
    {
        $promo = PromoCode::factory()->at($this->restaurant)->percent(25)->restaurantShare(50)->unlimitedPerUser()->create(['code' => 'OZGARADI']); // bir mijoz ikki marta
        $old = $this->deliver($this->placeOrder('OZGARADI'));
        $revenueBefore = $this->stats()->summary($this->period(), $this->restaurant->id)['revenue_tiyin'];
        $debtBefore = $this->stats()->platformDiscounts($this->period())[0]['platform_amount_tiyin'];

        // Admin keyin ulushni ham, chegirmani ham o'zgartiradi.
        $promo->update(['restaurant_share_percent' => 100, 'discount_value' => 50]);

        $old->refresh();
        $this->assertSame(16_000_00, $old->discount_amount);
        $this->assertSame(8_000_00, $old->discount_restaurant_amount);
        $this->assertSame(8_000_00, $old->discount_platform_amount);
        $this->assertSame($revenueBefore, $this->stats()->summary($this->period(), $this->restaurant->id)['revenue_tiyin']);
        $this->assertSame($debtBefore, $this->stats()->platformDiscounts($this->period())[0]['platform_amount_tiyin']);

        // Yangi buyurtma — yangi shartlar bilan.
        $new = $this->placeOrder('OZGARADI');
        $this->assertSame(32_000_00, $new->discount_amount);
        $this->assertSame(32_000_00, $new->discount_restaurant_amount);
        $this->assertSame(0, $new->discount_platform_amount);
    }

    // --- Toq so'm ------------------------------------------------------------

    public function test_odd_som_discount_gives_the_extra_som_to_the_platform_and_reconciles(): void
    {
        PromoCode::factory()->at($this->restaurant)->fixed(15_005_00)->restaurantShare(50)->create(['code' => 'TOQ']);

        $order = $this->deliver($this->placeOrder('TOQ'));

        $this->assertSame(7_502_00, $order->discount_restaurant_amount);
        $this->assertSame(7_503_00, $order->discount_platform_amount);

        // Hisobot/CSV so'mда: 7 502 + 7 503 = 15 005 (1 so'm ham yo'qolmaydi).
        $row = $this->stats()->platformDiscounts($this->period())[0];
        $this->assertSame(
            Money::toSoms($row['discount_tiyin']),
            Money::toSoms($row['restaurant_amount_tiyin']) + Money::toSoms($row['platform_amount_tiyin']),
        );
    }

    // --- Mini App va panel bir xil jami ko'rsatadi ----------------------------

    /**
     * Checkout.jsx: jami = savat (menyu narxlari) + /orders/estimate delivery_fee −
     * /promo-codes/validate discount_amount. Masofa narxi va foizli chegirma
     * yaxlitlanmaganда kasrli so'm berardi — endi ikkalasi butun so'm, shuning
     * uchun Mini App (Math.round) va PHP (intdiv) bir xil summa ko'rsatadi.
     */
    public function test_mini_app_checkout_total_equals_the_order_total_shown_everywhere(): void
    {
        $this->restaurant->update(['free_delivery_radius_km' => null, 'price_per_km' => 123_457]); // 1 234,57 so'm/km
        $this->osh->update(['price' => 12_345_00]);
        PromoCode::factory()->at($this->restaurant)->percent(15)->create(['code' => 'KASR']);

        // Yaxlitlashsiz ikkalasi ham kasrli so'm bo'lardi — test haqiqatan shu holatni sinaydi.
        $distanceKm = app(RestaurantFinder::class)->distanceKm($this->restaurant, $this->address);
        $this->assertNotSame(0, (int) round($distanceKm * 123_457) % 100, 'masofa narxi yaxlitlashsiz kasrli bo\'lishi kerak');
        $this->assertNotSame(0, (12_345_00 * 15) % 10_000, 'chegirma yaxlitlashsiz kasrli bo\'lishi kerak');

        // Mini App hisoblagani
        $menu = $this->getJson("/api/restaurants/{$this->restaurant->id}/menu", $this->headers())->assertOk()->json('data');
        $price = collect($menu)->flatMap(fn ($c) => $c['products'])->firstWhere('id', $this->osh->id)['price'];
        $cartSubtotal = $price * 1;
        $deliveryFee = $this->postJson('/api/orders/estimate', [
            'restaurant_id' => $this->restaurant->id, 'delivery_type' => 'delivery', 'address_id' => $this->address->id,
            'items' => [['product_id' => $this->osh->id, 'qty' => 1]],
        ], $this->headers())->assertOk()->json('data.delivery_fee');
        $discount = $this->postJson('/api/promo-codes/validate', [
            'promo_code' => 'KASR', 'restaurant_id' => $this->restaurant->id, 'subtotal' => $cartSubtotal,
        ], $this->headers())->assertOk()->json('data.discount_amount');
        $miniAppPayable = $cartSubtotal + $deliveryFee - $discount;

        // Haqiqiy buyurtma
        $order = $this->placeOrder('KASR', qty: 1);

        $this->assertSame($miniAppPayable, $order->total);
        $this->assertSame(0, $order->total % 100, 'total butun so\'m bo\'lishi kerak');
        $this->assertSame(0, $order->delivery_fee % 100);
        $this->assertSame(0, $order->discount_amount % 100);

        // JS som(): Math.round(tiyin / 100) ; PHP Money::soms(): intdiv(tiyin, 100)
        $jsDisplayed = number_format((int) round($miniAppPayable / 100), 0, '.', ' ')." so'm";
        $this->assertSame(Money::soms($order->total), $jsDisplayed);
    }
}
