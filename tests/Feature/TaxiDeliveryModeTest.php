<?php

namespace Tests\Feature;

use App\Enums\BeyondFreeZoneDelivery;
use App\Enums\CourierType;
use App\Enums\DeliveryType;
use App\Enums\OrderStatus;
use App\Filament\Admin\Resources\RestaurantResource\Pages\EditRestaurant;
use App\Jobs\NotifyCustomerOfStatusChange;
use App\Jobs\NotifyRestaurantOfNewOrder;
use App\Jobs\SendOrderConfirmationToCustomer;
use App\Models\Address;
use App\Models\Category;
use App\Models\District;
use App\Models\Order;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Models\User;
use App\Services\Delivery\DeliveryFeeCalculator;
use App\Services\Dispatch\ReceiptFormatter;
use App\Telegram\Support\KitchenOrderMessage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use SergiX44\Nutgram\Nutgram;
use Tests\Concerns\InteractsWithTelegramInitData;
use Tests\TestCase;

/**
 * Royal Taxi rejimi (restaurants.beyond_free_zone_delivery = taxi): bepul zonadan
 * tashqarida ilovada yetkazish narxi 0, mijoz taksi haqini haydovchiga alohida to'laydi.
 */
class TaxiDeliveryModeTest extends TestCase
{
    use InteractsWithTelegramInitData;
    use RefreshDatabase;

    private User $user;

    private Address $address;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindInitDataValidator();
        Http::fake();
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00', 'Asia/Tashkent'));

        $this->user = User::factory()->create(['telegram_id' => 910001, 'language' => 'uz']);
        // Restoranga ~0.07 km masofada.
        $this->address = Address::factory()->for($this->user)->default()->create(['lat' => 40.7830, 'lng' => 72.3500]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function restaurant(array $overrides = []): Restaurant
    {
        $always = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], [['00:00', '23:59']]);
        $restaurant = Restaurant::factory()->for(District::factory())->create(array_replace([
            'name' => 'Osh Markazi', 'lat' => 40.7833, 'lng' => 72.3506,
            'is_open' => true, 'work_hours' => $always,
            'delivery_radius_km' => 8, 'delivery_fee' => 1_500_000, 'min_order_amount' => 0,
            'free_delivery_radius_km' => 0.01, // buyurtma manzili (~0.07 km) bepul zonadan TASHQARIDA
            'price_per_km' => 200_000,
            'beyond_free_zone_delivery' => BeyondFreeZoneDelivery::Taxi,
        ], $overrides));

        $cat = Category::factory()->for($restaurant)->create(['is_active' => true]);
        Product::factory()->for($cat)->create(['name' => 'Osh', 'price' => 3_200_000, 'is_available' => true, 'prep_time_min' => 20]);

        return $restaurant;
    }

    private function headers(): array
    {
        return $this->initDataHeaders($this->signedInitData(['id' => $this->user->telegram_id]));
    }

    private function payload(Restaurant $restaurant, array $overrides = []): array
    {
        return array_replace([
            'restaurant_id' => $restaurant->id,
            'delivery_type' => 'delivery',
            'address_id' => $this->address->id,
            'items' => [['product_id' => $restaurant->categories()->first()->products()->first()->id, 'qty' => 1]],
        ], $overrides);
    }

    private function placeOrder(Restaurant $restaurant): Order
    {
        $this->postJson('/api/orders', $this->payload($restaurant), $this->headers())->assertCreated();

        return Order::withoutGlobalScopes()->latest('id')->first();
    }

    // --- Kalkulyator ----------------------------------------------------------------------

    public function test_default_is_per_km_and_per_km_restaurant_is_unchanged(): void
    {
        $calc = app(DeliveryFeeCalculator::class);
        $perKm = $this->restaurant(['beyond_free_zone_delivery' => BeyondFreeZoneDelivery::PerKm, 'free_delivery_radius_km' => 2, 'price_per_km' => 200_000]);

        $this->assertSame(BeyondFreeZoneDelivery::PerKm, Restaurant::factory()->create()->fresh()->beyond_free_zone_delivery, 'standart per_km');

        $inside = $calc->quote($perKm, DeliveryType::Delivery, 1.5);
        $beyond = $calc->quote($perKm, DeliveryType::Delivery, 5.0);

        $this->assertSame([0, false], [$inside->fee, $inside->paidToTaxi]);
        $this->assertSame([1_000_000, false], [$beyond->fee, $beyond->paidToTaxi]); // 5 km × 2 000 so'm
        $this->assertSame(1_000_000, $calc->calculate($perKm, DeliveryType::Delivery, 5.0));
    }

    public function test_taxi_mode_inside_free_zone_is_plain_free_without_flag(): void
    {
        $r = $this->restaurant(['free_delivery_radius_km' => 3]);
        $quote = app(DeliveryFeeCalculator::class)->quote($r, DeliveryType::Delivery, 2.0);
        $edge = app(DeliveryFeeCalculator::class)->quote($r, DeliveryType::Delivery, 3.0);

        $this->assertSame([0, false], [$quote->fee, $quote->paidToTaxi]);
        $this->assertSame([0, false], [$edge->fee, $edge->paidToTaxi], 'aynan radiusda hali bepul zona');
    }

    public function test_taxi_mode_beyond_free_zone_is_zero_with_taxi_flag(): void
    {
        $r = $this->restaurant(['free_delivery_radius_km' => 3]);
        $quote = app(DeliveryFeeCalculator::class)->quote($r, DeliveryType::Delivery, 3.01);

        $this->assertSame([0, true], [$quote->fee, $quote->paidToTaxi]);
    }

    public function test_taxi_mode_pickup_and_missing_free_radius_never_flag(): void
    {
        $calc = app(DeliveryFeeCalculator::class);
        $r = $this->restaurant(['free_delivery_radius_km' => 3]);

        $pickup = $calc->quote($r, DeliveryType::Pickup, 9.0);
        $this->assertSame([0, false], [$pickup->fee, $pickup->paidToTaxi]);

        // Bepul radius yo'q — "bepul zona" yo'q, taksi rejimi e'tiborga olinmaydi, oddiy narx ishlaydi.
        $noRadius = $this->restaurant(['free_delivery_radius_km' => null]);
        $quote = $calc->quote($noRadius, DeliveryType::Delivery, 4.0);
        $this->assertSame([800_000, false], [$quote->fee, $quote->paidToTaxi]);
    }

    // --- Estimate va place bir xil -------------------------------------------------------

    public function test_estimate_and_place_agree_beyond_the_free_zone(): void
    {
        $restaurant = $this->restaurant();

        $estimate = $this->postJson('/api/orders/estimate', $this->payload($restaurant), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.delivery_fee', 0)
            ->assertJsonPath('data.delivery_paid_to_taxi', true);

        $order = $this->placeOrder($restaurant);

        $this->assertSame($estimate->json('data.delivery_fee'), $order->delivery_fee);
        $this->assertTrue($order->delivery_paid_to_taxi);
        $this->assertSame($order->subtotal, $order->total, 'jami faqat taomlar');
    }

    public function test_estimate_and_place_agree_inside_the_free_zone(): void
    {
        $restaurant = $this->restaurant(['free_delivery_radius_km' => 5]);

        $this->postJson('/api/orders/estimate', $this->payload($restaurant), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.delivery_fee', 0)
            ->assertJsonPath('data.delivery_paid_to_taxi', false);

        $order = $this->placeOrder($restaurant);

        $this->assertSame(0, $order->delivery_fee);
        $this->assertFalse($order->delivery_paid_to_taxi);
    }

    public function test_per_km_restaurant_estimate_and_order_are_unchanged(): void
    {
        $restaurant = $this->restaurant(['beyond_free_zone_delivery' => BeyondFreeZoneDelivery::PerKm]);

        $estimate = $this->postJson('/api/orders/estimate', $this->payload($restaurant), $this->headers())
            ->assertOk()->assertJsonPath('data.delivery_paid_to_taxi', false);

        $order = $this->placeOrder($restaurant);

        $this->assertGreaterThan(0, $order->delivery_fee);
        $this->assertSame($estimate->json('data.delivery_fee'), $order->delivery_fee);
        $this->assertFalse($order->delivery_paid_to_taxi);
    }

    public function test_max_delivery_radius_still_applies_in_taxi_mode(): void
    {
        $restaurant = $this->restaurant(['delivery_radius_km' => 0.01]); // manzil (~0.07 km) radiusdan tashqarida

        $this->postJson('/api/orders', $this->payload($restaurant), $this->headers())->assertStatus(422);
    }

    // --- Snapshot ------------------------------------------------------------------------

    public function test_changing_the_restaurant_setting_later_does_not_change_old_orders(): void
    {
        $restaurant = $this->restaurant();
        $taxiOrder = $this->placeOrder($restaurant);

        $restaurant->update(['beyond_free_zone_delivery' => BeyondFreeZoneDelivery::PerKm]);
        $perKmOrder = $this->placeOrder($restaurant);
        $restaurant->update(['beyond_free_zone_delivery' => BeyondFreeZoneDelivery::Taxi]);

        $this->assertTrue($taxiOrder->fresh()->delivery_paid_to_taxi);
        $this->assertSame(0, $taxiOrder->fresh()->delivery_fee);
        $this->assertFalse($perKmOrder->fresh()->delivery_paid_to_taxi);
        $this->assertGreaterThan(0, $perKmOrder->fresh()->delivery_fee);

        $this->getJson("/api/orders/{$taxiOrder->id}", $this->headers())->assertOk()->assertJsonPath('data.delivery_paid_to_taxi', true);
    }

    // --- Matnlar: faqat kerakli holatda ----------------------------------------------------

    private function flagged(bool $taxi, string $lang = 'uz'): Order
    {
        $this->user->update(['language' => $lang]);
        $restaurant = Restaurant::factory()->for(District::factory())->create(['name' => 'Osh Markazi']);

        return Order::factory()->for($restaurant)->for($this->user)->create([
            'status' => OrderStatus::Accepted,
            'delivery_type' => DeliveryType::Delivery,
            'delivery_fee' => 0,
            'delivery_paid_to_taxi' => $taxi,
            'subtotal' => 3_200_000,
            'total' => 3_200_000,
            'address_snapshot' => ['address_text' => 'Navoi 1', 'lat' => 40.78, 'lng' => 72.35],
        ]);
    }

    private function body(callable $send): string
    {
        // Har chaqiruvga yangi (toza) Nutgram: aks holda oldingi so'rovlar ham yig'ilib qoladi.
        $this->app->forgetInstance(Nutgram::class);
        $bot = app(Nutgram::class);
        $send($bot);
        $captured = '';
        $bot->assertRaw(function ($request) use (&$captured) {
            $captured .= (string) $request->getBody();

            return true;
        });

        return $captured;
    }

    public function test_customer_receipt_says_taxi_only_when_flagged(): void
    {
        $with = $this->body(fn ($bot) => (new SendOrderConfirmationToCustomer($this->flagged(true)->id))->handle($bot));
        $without = $this->body(fn ($bot) => (new SendOrderConfirmationToCustomer($this->flagged(false)->id))->handle($bot));

        $this->assertStringContainsString('Yetkazish: Royal Taxi — haydovchiga alohida', $with);
        $this->assertStringNotContainsString('Royal Taxi', $without);
        $this->assertStringContainsString('bepul', $without);
    }

    public function test_customer_receipt_in_russian(): void
    {
        $with = $this->body(fn ($bot) => (new SendOrderConfirmationToCustomer($this->flagged(true, 'ru')->id))->handle($bot));

        $this->assertStringContainsString('Доставка: Royal Taxi — водителю отдельно', $with);
    }

    public function test_kitchen_bot_message_card_and_receipt_text_only_when_flagged(): void
    {
        $flagged = $this->flagged(true)->load(['user', 'restaurant']);
        $plain = $this->flagged(false)->load(['user', 'restaurant']);
        $message = app(KitchenOrderMessage::class);

        $text = $message->text($flagged);
        $this->assertStringContainsString('🚕 Yetkazish haqi: mijoz taksiga alohida to‘laydi', $text);
        $this->assertStringContainsString('Mijozdan olinadi: 32 000 so\'m', $text);
        $this->assertStringNotContainsString('taksiga', $message->text($plain));

        $escpos = app(ReceiptFormatter::class);
        // Printer kengligida qatorlarga bo'linadi — bo'shliqlarni tekislab solishtiramiz.
        $flat = fn (Order $o): string => preg_replace('/\s+/', ' ',$escpos->format($o)['text']);
        $this->assertStringContainsString('Yetkazish haqi: mijoz taksiga alohida tolaydi', $flat($flagged));
        $this->assertStringNotContainsString('taksiga', $flat($plain));

        $this->assertTrue(collect([$flagged])->map(fn ($o) => (new \App\Http\Resources\KitchenOrderResource($o))->toArray(request())['delivery_paid_to_taxi'])->first());
    }

    public function test_owner_dm_mentions_taxi_only_when_flagged(): void
    {
        $with = $this->flagged(true);
        $with->restaurant->update(['notify_chat_id' => 123456]);
        $without = $this->flagged(false);
        $without->restaurant->update(['notify_chat_id' => 123457]);

        $a = $this->body(fn ($bot) => (new NotifyRestaurantOfNewOrder($with->id))->handle($bot));
        $b = $this->body(fn ($bot) => (new NotifyRestaurantOfNewOrder($without->id))->handle($bot));

        $this->assertStringContainsString('mijoz taksiga alohida to‘laydi', $a);
        $this->assertStringNotContainsString('taksiga', $b);
    }

    public function test_on_the_way_message_adds_taxi_note_only_for_flagged_taxi_orders(): void
    {
        $send = function (bool $flag, CourierType $courier): string {
            $order = $this->flagged($flag);
            $order->update(['status' => OrderStatus::OnTheWay, 'courier_type' => $courier, 'courier_phone' => '+998901112233']);

            return $this->body(fn ($bot) => (new NotifyCustomerOfStatusChange($order->id))->handle($bot));
        };

        $this->assertStringContainsString('Yetkazish haqini taksi haydovchisiga to‘laysiz', $send(true, CourierType::Taxi));
        // Bepul zonada taksi tanlansa — restoran o'zi to'laydi, mijozga hech narsa yozilmaydi.
        $this->assertStringNotContainsString('to‘laysiz', $send(false, CourierType::Taxi));
        $this->assertStringContainsString('Royal Taxi', $send(false, CourierType::Taxi));
        // Belgi bor, lekin kuryer o'zimiznikilar — taksi haqida gap yo'q.
        $this->assertStringNotContainsString('to‘laysiz', $send(true, CourierType::OwnStaff));
    }

    // --- /admin forma --------------------------------------------------------------------

    public function test_admin_form_saves_the_mode_and_requires_free_radius_for_taxi(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs(Staff::factory()->platformAdmin()->create(), 'admin');
        $restaurant = Restaurant::factory()->create(['free_delivery_radius_km' => null]);

        Livewire::test(EditRestaurant::class, ['record' => $restaurant->getRouteKey()])
            ->fillForm(['beyond_free_zone_delivery' => 'taxi', 'free_delivery_radius_km' => null])
            ->call('save')
            ->assertHasFormErrors(['free_delivery_radius_km' => 'required']);
        $this->assertSame(BeyondFreeZoneDelivery::PerKm, $restaurant->fresh()->beyond_free_zone_delivery);

        Livewire::test(EditRestaurant::class, ['record' => $restaurant->getRouteKey()])
            ->fillForm(['beyond_free_zone_delivery' => 'taxi', 'free_delivery_radius_km' => 3])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(BeyondFreeZoneDelivery::Taxi, $restaurant->fresh()->beyond_free_zone_delivery);
    }
}
