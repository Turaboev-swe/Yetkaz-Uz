<?php

namespace Tests\Feature;

use App\Enums\DeliveryType;
use App\Enums\PosType;
use App\Filament\Admin\Resources\OrderResource\Pages\ViewOrder as AdminViewOrder;
use App\Filament\Restaurant\Resources\OrderResource\Pages\ViewOrder as RestaurantViewOrder;
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
use App\Services\Dispatch\ReceiptFormatter;
use App\Support\OrderAddress;
use App\Telegram\Support\KitchenOrderMessage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\Concerns\InteractsWithTelegramInitData;
use Tests\TestCase;

/**
 * Mo'ljal (addresses.note) buyurtma snapshot'iga `landmark` bo'lib tushadi va oshxona kartasi, chek (ESC/POS + brauzer),
 * Telegram xabarlari va admin/restoran buyurtma tafsilotida ko'rinadi. Ko'cha (street) ham snapshot'da.
 */
class OrderLandmarkTest extends TestCase
{
    use InteractsWithTelegramInitData;
    use RefreshDatabase;

    private const LANDMARK = 'Hokimiyat orqasida, ko‘k darvoza';

    private Restaurant $restaurant;

    private Staff $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindInitDataValidator();
        Http::fake();
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-04 12:00', 'Asia/Tashkent'));

        $always = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], [['00:00', '23:59']]);
        $this->restaurant = Restaurant::factory()->for(District::factory())->create([
            'name' => 'Mo‘ljal Resto', 'pos_type' => PosType::Browser, 'receipt_paper_mm' => 80,
            'lat' => 40.7833, 'lng' => 72.3506, 'is_open' => true, 'work_hours' => $always,
            'delivery_radius_km' => 10, 'delivery_fee' => 1_000_000, 'min_order_amount' => 0,
        ]);
        $this->owner = Staff::factory()->owner($this->restaurant)->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(?string $landmark = self::LANDMARK, array $extra = [], array $snap = []): Order
    {
        return Order::factory()->for($this->restaurant)->for(User::factory(['language' => 'uz']))->create(array_replace([
            'delivery_type' => DeliveryType::Delivery,
            'address_snapshot' => array_replace([
                'label' => 'Uy', 'address_text' => "Bobur ko'chasi 12, Qo'rg'ontepa tumani", 'street' => "Bobur ko'chasi 12",
                'district' => "Qo'rg'ontepa tumani", 'lat' => 40.73, 'lng' => 72.76, 'entrance' => '2', 'floor' => '3', 'apartment' => '14',
                'landmark' => $landmark,
            ], $snap),
        ], $extra));
    }

    // --- Snapshot ---------------------------------------------------------------------------------

    public function test_a_real_order_snapshots_the_street_and_the_landmark(): void
    {
        $user = User::factory()->create(['telegram_id' => 960001, 'phone' => '+998901112233']);
        $address = Address::factory()->for($user)->default()->create([
            'lat' => 40.7830, 'lng' => 72.3500, 'street' => "Bobur ko'chasi 12", 'note' => '  '.self::LANDMARK.'  ',
            'address_text' => "Bobur ko'chasi 12, Andijon", 'district_id' => null,
        ]);
        $product = Product::factory()->for(Category::factory()->for($this->restaurant)->create(['is_active' => true]))->create(['price' => 3_200_000, 'is_available' => true]);

        $id = $this->postJson('/api/orders', [
            'restaurant_id' => $this->restaurant->id, 'delivery_type' => 'delivery', 'address_id' => $address->id,
            'items' => [['product_id' => $product->id, 'qty' => 1]],
        ], $this->initDataHeaders($this->signedInitData(['id' => $user->telegram_id])))->assertCreated()->json('data.id');

        $snap = Order::withoutGlobalScopes()->findOrFail($id)->address_snapshot;
        $this->assertSame("Bobur ko'chasi 12", $snap['street']);
        $this->assertSame(self::LANDMARK, $snap['landmark'], 'bo‘shliqlar kesilgan');

        // Snapshot: manzil keyin o'zgarsa ham eski buyurtma o'zgarmaydi.
        $address->update(['note' => 'Boshqa mo‘ljal', 'street' => 'Yangi ko‘cha']);
        $fresh = Order::withoutGlobalScopes()->findOrFail($id)->address_snapshot;
        $this->assertSame(self::LANDMARK, $fresh['landmark']);
        $this->assertSame("Bobur ko'chasi 12", $fresh['street']);
    }

    public function test_an_address_without_a_landmark_snapshots_null(): void
    {
        $user = User::factory()->create(['telegram_id' => 960002, 'phone' => '+998901112244']);
        $address = Address::factory()->for($user)->default()->create(['lat' => 40.7830, 'lng' => 72.3500, 'street' => null, 'note' => null]);
        $product = Product::factory()->for(Category::factory()->for($this->restaurant)->create(['is_active' => true]))->create(['is_available' => true]);

        $id = $this->postJson('/api/orders', [
            'restaurant_id' => $this->restaurant->id, 'delivery_type' => 'delivery', 'address_id' => $address->id,
            'items' => [['product_id' => $product->id, 'qty' => 1]],
        ], $this->initDataHeaders($this->signedInitData(['id' => $user->telegram_id])))->assertCreated()->json('data.id');

        $snap = Order::withoutGlobalScopes()->findOrFail($id)->address_snapshot;
        $this->assertNull($snap['landmark']);
        $this->assertNull($snap['street']);
    }

    public function test_old_snapshots_without_the_key_have_no_landmark(): void
    {
        $this->assertNull(OrderAddress::landmark(['address_text' => 'Eski']));
        $this->assertNull(OrderAddress::landmark(null));
        $this->assertNull(OrderAddress::landmark(['landmark' => '   ']));
        $this->assertSame('Bozor yonida', OrderAddress::landmark(['landmark' => ' Bozor yonida ']));
    }

    // --- Oshxona paneli ---------------------------------------------------------------------------

    public function test_the_kitchen_panel_gets_the_landmark(): void
    {
        $with = $this->order();
        $without = $this->order(null);

        $rows = collect($this->actingAs($this->owner, 'staff')->getJson('/kitchen/orders')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame(self::LANDMARK, $rows[$with->id]['address']['landmark']);
        $this->assertNull($rows[$without->id]['address']['landmark']);
    }

    public function test_a_pickup_order_has_no_address_block(): void
    {
        $pickup = $this->order(self::LANDMARK, ['delivery_type' => DeliveryType::Pickup, 'address_snapshot' => null]);

        $row = collect($this->actingAs($this->owner, 'staff')->getJson('/kitchen/orders')->assertOk()->json('data'))->keyBy('id')[$pickup->id];

        $this->assertNull($row['address']);
    }

    // --- Chek -------------------------------------------------------------------------------------

    private function escpos(Order $order): string
    {
        return app(ReceiptFormatter::class)->format($order->fresh(['user', 'restaurant']))['text'];
    }

    public function test_the_escpos_receipt_prints_the_landmark(): void
    {
        $order = $this->order();

        $text = $this->escpos($order);

        $this->assertStringContainsString('MOLJAL:', $text);
        $this->assertStringContainsString("Hokimiyat orqasida, ko'k darvoza", $text, 'ESC/POS: ASCII (‘ -> \')');
        $this->assertSame(self::LANDMARK, app(ReceiptFormatter::class)->data($order->fresh(['user', 'restaurant']))->landmark);
    }

    public function test_the_escpos_receipt_has_no_landmark_row_without_one(): void
    {
        $this->assertStringNotContainsString('MOLJAL', $this->escpos($this->order(null)));
    }

    public function test_the_escpos_landmark_wraps_inside_the_paper_width(): void
    {
        foreach ([32, 42, 48] as $columns) {
            $this->restaurant->forceFill(['receipt_columns' => $columns])->save();
            $order = $this->order(str_repeat('juda uzun mo‘ljal matni ', 8));

            foreach (explode("\n", rtrim($this->escpos($order))) as $line) {
                $this->assertLessThanOrEqual($columns, mb_strlen($line), "{$columns}: {$line}");
            }
        }
    }

    public function test_a_pickup_receipt_has_no_landmark(): void
    {
        $pickup = $this->order(self::LANDMARK, ['delivery_type' => DeliveryType::Pickup, 'address_snapshot' => null]);

        $this->assertStringNotContainsString('MOLJAL', $this->escpos($pickup));
        $this->assertNull(app(ReceiptFormatter::class)->data($pickup->fresh(['user', 'restaurant']))->landmark);
    }

    public function test_the_browser_receipt_shows_the_landmark_in_uzbek_and_russian(): void
    {
        $order = $this->order();

        $html = $this->actingAs($this->owner, 'staff')->get("/kitchen/orders/{$order->id}/receipt")->assertOk()->getContent();
        $this->assertStringContainsString('MO‘LJAL', $html);
        $this->assertStringContainsString(htmlspecialchars(self::LANDMARK, ENT_QUOTES), $html);

        config(['print.browser.locale' => 'ru']);
        $ru = $this->actingAs($this->owner, 'staff')->get("/kitchen/orders/{$order->id}/receipt")->assertOk()->getContent();
        $this->assertStringContainsString('ОРИЕНТИР', $ru);
    }

    public function test_the_browser_receipt_has_no_landmark_row_without_one(): void
    {
        $order = $this->order(null);

        $this->actingAs($this->owner, 'staff')->get("/kitchen/orders/{$order->id}/receipt")->assertOk()
            ->assertDontSee('MO‘LJAL');
    }

    public function test_a_landmark_alone_still_shows_the_address_block_on_the_browser_receipt(): void
    {
        $order = $this->order(self::LANDMARK, [], ['address_text' => '', 'district' => null, 'entrance' => null, 'floor' => null, 'apartment' => null]);

        $this->actingAs($this->owner, 'staff')->get("/kitchen/orders/{$order->id}/receipt")->assertOk()->assertSee('MO‘LJAL');
    }

    // --- Telegram ---------------------------------------------------------------------------------

    public function test_the_kitchen_bot_message_shows_the_landmark(): void
    {
        $text = app(KitchenOrderMessage::class)->text($this->order());

        $this->assertStringContainsString('🧭 Mo‘ljal: '.self::LANDMARK, $text);
        $this->assertStringNotContainsString('🧭', app(KitchenOrderMessage::class)->text($this->order(null)));
    }

    public function test_the_kitchen_bot_message_escapes_html_in_the_landmark(): void
    {
        $text = app(KitchenOrderMessage::class)->text($this->order('<b>x</b> & y'));

        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt; &amp; y', $text);
    }

    public function test_the_owner_dm_shows_the_landmark(): void
    {
        $order = $this->order();
        $method = new ReflectionMethod(NotifyRestaurantOfNewOrder::class, 'message');

        $text = $method->invoke(new NotifyRestaurantOfNewOrder($order->id), $order->fresh(['user', 'restaurant']));

        $this->assertStringContainsString('🧭 Mo‘ljal: '.self::LANDMARK, $text);
    }

    public function test_the_customer_confirmation_shows_the_landmark_in_the_customers_language(): void
    {
        $order = $this->order();
        $method = new ReflectionMethod(SendOrderConfirmationToCustomer::class, 'receipt');
        $job = new SendOrderConfirmationToCustomer($order->id);

        $uz = $method->invoke($job, $order->fresh(['user', 'restaurant']));
        $this->assertStringContainsString('🧭 Mo‘ljal: '.self::LANDMARK, $uz);

        $order->user->update(['language' => 'ru']);
        $ru = $method->invoke($job, $order->fresh(['user', 'restaurant']));
        $this->assertStringContainsString('🧭 Ориентир: '.self::LANDMARK, $ru);

        $this->assertStringNotContainsString('🧭', $method->invoke($job, $this->order(null)->fresh(['user', 'restaurant'])));
    }

    // --- Admin va restoran paneli ---------------------------------------------------------------------

    public function test_the_restaurant_panel_order_page_shows_the_landmark_once(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('restaurant'));
        Livewire::actingAs($this->owner, 'staff');
        $order = $this->order();

        $html = Livewire::test(RestaurantViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertSee('Mo‘ljal')
            ->assertSee(self::LANDMARK)
            ->html();

        $this->assertSame(1, substr_count($html, 'Hokimiyat orqasida'), 'xom kalit/qiymat jadvalida takrorlanmaydi');
    }

    public function test_the_admin_order_page_shows_the_landmark_and_a_dash_when_missing(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs(Staff::factory()->platformAdmin()->create(), 'admin');

        Livewire::test(AdminViewOrder::class, ['record' => $this->order()->getRouteKey()])
            ->assertSee('Mo‘ljal')
            ->assertSee(self::LANDMARK);

        Livewire::test(AdminViewOrder::class, ['record' => $this->order(null)->getRouteKey()])
            ->assertSee('Mo‘ljal');
    }

    public function test_the_customer_api_order_resource_carries_the_snapshot_with_the_landmark(): void
    {
        $user = User::factory()->create(['telegram_id' => 960003]);
        $order = Order::factory()->for($this->restaurant)->for($user)->create([
            'address_snapshot' => ['address_text' => 'Matn', 'landmark' => self::LANDMARK, 'street' => 'Ko‘cha'],
        ]);

        $this->getJson("/api/orders/{$order->id}", $this->initDataHeaders($this->signedInitData(['id' => 960003])))
            ->assertOk()
            ->assertJsonPath('data.address_snapshot.landmark', self::LANDMARK);
    }
}
