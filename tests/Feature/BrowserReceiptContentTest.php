<?php

namespace Tests\Feature;

use App\Enums\BeyondFreeZoneDelivery;
use App\Enums\DeliveryType;
use App\Enums\OrderStatus;
use App\Enums\PosType;
use App\Models\Address;
use App\Models\Category;
use App\Models\District;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Models\User;
use App\Services\Dispatch\ReceiptFormatter;
use App\Support\QrCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithTelegramInitData;
use Tests\TestCase;

/**
 * Chek mazmuni (brauzer cheki va ESC/POS bir manbadan — ReceiptFormatter::data): manzil takrori, o'rinbosar ism,
 * qisqa vaqt, narxlar (buyurtma paytidagi snapshot) va hisob to'g'riligi.
 */
class BrowserReceiptContentTest extends TestCase
{
    use InteractsWithTelegramInitData;
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Staff $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindInitDataValidator();
        Http::fake();

        $this->restaurant = $this->makeRestaurant();
        $this->owner = Staff::factory()->owner($this->restaurant)->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeRestaurant(array $extra = []): Restaurant
    {
        $always = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], [['00:00', '23:59']]);

        return Restaurant::factory()->for(District::factory())->create(array_replace([
            'name' => 'Guzar Kafe', 'pos_type' => PosType::Browser, 'receipt_paper_mm' => 80,
            'lat' => 40.7833, 'lng' => 72.3506, 'is_open' => true, 'work_hours' => $always,
            'delivery_radius_km' => 10, 'delivery_fee' => 1_000_000, 'min_order_amount' => 0,
        ], $extra));
    }

    private function order(array $extra = [], array $user = []): Order
    {
        return Order::factory()->for($this->restaurant)
            ->for(User::factory(array_replace(['full_name' => 'Ali Valiyev', 'phone' => '+998900000000'], $user)))
            ->create(array_replace([
                'status' => OrderStatus::New,
                'delivery_type' => DeliveryType::Delivery,
                'items' => [['product_id' => 1, 'name' => 'Osh', 'price' => 3_200_000, 'qty' => 1, 'prep' => 10, 'note' => null]],
                'note' => null,
                'contact_phone' => '+998900000000',
                'delivery_fee' => 0,
                'subtotal' => 3_200_000,
                'total' => 3_200_000,
                'address_snapshot' => ['address_text' => 'Chilonzor, 5-uy', 'district' => 'Chilonzor'],
            ], $extra));
    }

    /** Chek sahifasining ko'rinadigan matni (teglarsiz, bo'shliqlar bitta). */
    private function visibleText(Order $order): string
    {
        $html = $this->actingAs($this->owner, 'staff')->get("/kitchen/orders/{$order->id}/receipt")->assertOk()->getContent();
        $html = (string) preg_replace('#<(style|svg)\b.*?</\1>#s', '', $html);

        // Teglar o'rniga bo'shliq: flex qatorlardagi yorliq va qiymat (<span>…</span><span>…</span>) yopishib qolmasin.
        $text = html_entity_decode((string) preg_replace('/<[^>]+>/', ' ', $html), ENT_QUOTES | ENT_HTML5);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function escpos(Order $order): string
    {
        return app(ReceiptFormatter::class)->format($order->fresh(['user', 'restaurant']))['text'];
    }

    // --- Manzil takrori --------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function duplicatedDistricts(): array
    {
        return [
            'bir xil matn' => ["Qo'rg'ontepa tumani", "Qo'rg'ontepa tumani", "Qo'rg'ontepa tumani"],
            'apostrof farqi (ASCII va ‘ ’)' => ["Qo'rg'ontepa tumani", 'Qo‘rg‘ontepa tumani', "Qo'rg'ontepa tumani"],
            'registr farqi' => ["QO'RG'ONTEPA TUMANI", "Qo'rg'ontepa tumani", "QO'RG'ONTEPA TUMANI"],
            'tuman manzil ichida' => ["Bobur ko'chasi 5, Qo'rg'ontepa tumani", 'Qo‘rg‘ontepa tumani', "Bobur ko'chasi 5, Qo'rg'ontepa tumani"],
        ];
    }

    #[DataProvider('duplicatedDistricts')]
    public function test_district_already_in_the_address_is_not_added_again(string $text, string $district, string $expected): void
    {
        $order = $this->order(['address_snapshot' => ['address_text' => $text, 'district' => $district]]);

        $this->assertSame($expected, app(ReceiptFormatter::class)->data($order->fresh(['user', 'restaurant']))->addressText);

        // ESC/POS (apostrof ASCIIga keltiriladi) va HTML — tuman nomi bir marta.
        $this->assertSame(1, substr_count(strtolower($this->escpos($order)), 'tumani'), 'ESC/POS');
        $this->assertSame(1, substr_count(mb_strtolower($this->visibleText($order)), 'tumani'), 'HTML');
    }

    public function test_district_is_added_once_when_the_address_does_not_have_it(): void
    {
        $order = $this->order(['address_snapshot' => ['address_text' => "Bobur ko'chasi 5", 'district' => 'Chilonzor tumani']]);

        $this->assertSame("Bobur ko'chasi 5, Chilonzor tumani", app(ReceiptFormatter::class)->data($order->fresh(['user', 'restaurant']))->addressText);
    }

    public function test_raw_coordinates_are_replaced_by_the_district(): void
    {
        $order = $this->order(['address_snapshot' => ['address_text' => '40.716863, 72.768369', 'district' => 'Qo‘rg‘ontepa tumani']]);

        $this->assertSame('Qo‘rg‘ontepa tumani', app(ReceiptFormatter::class)->data($order->fresh(['user', 'restaurant']))->addressText);
        $this->assertStringNotContainsString('40.716863', $this->visibleText($order));
    }

    // --- Ism ------------------------------------------------------------------------------------

    /** @return array<string, array{0: ?string}> */
    public static function placeholderNames(): array
    {
        return ['Mijoz' => ['Mijoz'], 'kichik harf va bo‘shliq' => ['  mijoz '], 'bosh harf' => ['MIJOZ'], 'ruscha' => ['Клиент'], 'bo‘sh' => [''], 'null' => [null]];
    }

    #[DataProvider('placeholderNames')]
    public function test_a_missing_or_placeholder_name_prints_no_customer_row(?string $name): void
    {
        $order = $this->order([], ['full_name' => $name]);

        $this->assertStringNotContainsString('Mijoz:', $this->escpos($order));
        $this->assertStringContainsString('Tel: +998900000000', $this->escpos($order));

        $text = $this->visibleText($order);
        $this->assertStringNotContainsString('Mijoz', $text);
        $this->assertStringNotContainsString('Клиент', $text);
        $this->assertStringContainsString('Tel: +998900000000', $text);
    }

    public function test_a_real_name_is_kept_even_if_it_contains_the_word(): void
    {
        $order = $this->order([], ['full_name' => 'Mijozbek Aliyev']);

        $this->assertStringContainsString('Mijoz: Mijozbek Aliyev', $this->escpos($order));
        $this->assertStringContainsString('Mijozbek Aliyev · +998900000000', $this->visibleText($order));
    }

    // --- Vaqt -----------------------------------------------------------------------------------

    public function test_todays_order_shows_only_the_time_next_to_the_ready_estimate(): void
    {
        Carbon::setTestNow('2026-11-03 13:30:00');   // UTC = 18:30 Toshkent
        $order = $this->order(['eta_minutes' => 32, 'created_at' => '2026-11-03 12:58:00']);   // 17:58 Toshkent

        $data = app(ReceiptFormatter::class)->data($order->fresh(['user', 'restaurant']));

        $this->assertSame('17:58', $data->whenShort);
        $this->assertSame('03.11.2026 17:58', $data->when, 'ESC/POS cheki to‘liq sanani saqlaydi');
        $this->assertStringContainsString('17:58 · Tayyor ~32 daq', $this->visibleText($order));
    }

    public function test_an_older_order_keeps_the_date_and_the_ready_row_is_optional(): void
    {
        Carbon::setTestNow('2026-11-03 13:30:00');
        $order = $this->order(['eta_minutes' => null, 'created_at' => '2026-11-02 12:58:00']);

        $this->assertSame('02.11 17:58', app(ReceiptFormatter::class)->data($order->fresh(['user', 'restaurant']))->whenShort);
        $text = $this->visibleText($order);
        $this->assertStringContainsString('02.11 17:58', $text);
        $this->assertStringNotContainsString('Tayyor', $text);
    }

    public function test_today_is_decided_in_tashkent_time_not_utc(): void
    {
        // Hozir 2026-11-03 20:30 UTC = 04.11 01:30 Toshkent.
        Carbon::setTestNow('2026-11-03 20:30:00');
        $format = fn (Order $o) => app(ReceiptFormatter::class)->data($o->fresh(['user', 'restaurant']))->whenShort;

        // UTC bo'yicha shu kun (03.11), Toshkent bo'yicha kecha -> sana ko'rsatiladi.
        $yesterday = $this->order(['created_at' => '2026-11-03 18:00:00']);   // 23:00 Toshkent, 03.11
        $this->assertSame('03.11 23:00', $format($yesterday));

        // UTC bo'yicha shu kun, Toshkent bo'yicha bugun (04.11) -> faqat vaqt.
        $today = $this->order(['created_at' => '2026-11-03 20:10:00']);   // 01:10 Toshkent, 04.11
        $this->assertSame('01:10', $format($today));
    }
    // --- Narxlar -----------------------------------------------------------------------------------

    public function test_line_prices_come_from_the_order_snapshot_not_the_current_menu(): void
    {
        $cat = Category::factory()->for($this->restaurant)->create();
        $product = Product::factory()->for($cat)->create(['name' => 'Osh', 'price' => 3_000_000]);
        $order = $this->order([
            'items' => [['product_id' => $product->id, 'name' => 'Osh', 'price' => 3_000_000, 'qty' => 2, 'prep' => 10, 'note' => null]],
            'subtotal' => 6_000_000, 'total' => 6_000_000,
        ]);

        $product->update(['price' => 9_999_900]);   // menyuda narx keyin o'zgardi

        $text = $this->visibleText($order);
        $this->assertStringContainsString('2x Osh 60 000', $text);
        $this->assertStringNotContainsString('99 999', $text);
        $this->assertMatchesRegularExpression('/2x  Osh\s+60 000$/m', $this->escpos($order));
    }

    public function test_an_item_without_a_stored_price_prints_no_amount_and_falls_back_to_the_order_subtotal(): void
    {
        $order = $this->order([
            'items' => [['product_id' => 1, 'name' => 'Eski buyurtma taomi', 'qty' => 1, 'prep' => 10, 'note' => null]],
            'subtotal' => 4_000_000, 'total' => 4_000_000,
        ]);

        $data = app(ReceiptFormatter::class)->data($order->fresh(['user', 'restaurant']));

        $this->assertNull($data->items[0]['total']);
        $this->assertSame(4_000_000, $data->itemsTotal);
        $this->assertStringContainsString('1x Eski buyurtma taomi', $this->visibleText($order));
        $this->assertMatchesRegularExpression('/^1x  Eski buyurtma taomi$/m', $this->escpos($order));
    }

    /** @return array<string, array{0: int}> */
    public static function columns(): array
    {
        return ['58 mm / 32' => [32], '80 mm / 42' => [42], '80 mm / 48' => [48]];
    }

    #[DataProvider('columns')]
    public function test_escpos_prices_sit_in_the_right_column_and_never_overflow(int $columns): void
    {
        $this->restaurant->forceFill(['receipt_columns' => $columns, 'receipt_paper_mm' => $columns === 32 ? 58 : 80])->save();
        $order = $this->order([
            'items' => [
                ['product_id' => 1, 'name' => 'Lag\'mon', 'price' => 1_500_000, 'qty' => 2, 'prep' => 1, 'note' => null],
                ['product_id' => 2, 'name' => "Juda uzun nomli taom: Lag'mon ustiga qo'shimcha go'sht, sabzavot va achchiq sous", 'price' => 123_456_700, 'qty' => 3, 'prep' => 1, 'note' => 'izoh'],
            ],
            'subtotal' => 373_370_100, 'total' => 373_370_100,
        ]);

        $lines = explode("\n", rtrim($this->escpos($order)));

        foreach ($lines as $line) {
            $this->assertLessThanOrEqual($columns, mb_strlen($line), "qator {$columns} dan uzun: {$line}");
        }
        $short = array_values(array_filter($lines, fn ($l) => str_starts_with($l, '2x  ')))[0];
        $long = array_values(array_filter($lines, fn ($l) => str_starts_with($l, '3x  ')))[0];
        $this->assertSame($columns, mb_strlen($short), 'narx o‘ng chetga tekis');
        $this->assertStringEndsWith('30 000', $short);
        $this->assertSame($columns, mb_strlen($long));
        $this->assertStringEndsWith('3 703 701', $long);   // 3 x 1 234 567 so'm
    }

    // --- QR va til -----------------------------------------------------------------------------------

    public function test_the_bot_qr_has_31_modules_so_15_5_mm_is_exactly_4_dots_per_module_at_203_dpi(): void
    {
        $svg = QrCode::svg('https://t.me/Yetkaz_uzbot', margin: 1);

        $this->assertSame(1, preg_match('/scale\(([0-9.]+)\)/', $svg, $m));
        $this->assertSame(31, (int) round(256 / (float) $m[1]), 'modullar (chetlar bilan)');
        $this->assertSame(15.5, config('print.browser.qr_mm'));
        $this->assertSame(124.0, 15.5 * 8, '124 nuqta / 31 modul = 4 nuqta');
    }

    public function test_receipt_language_follows_the_setting_not_the_customers_language(): void
    {
        $order = $this->order();
        $order->user->update(['language' => 'ru']);

        $this->assertStringContainsString('MIJOZDAN OLINADI', $this->visibleText($order), 'standart — uz');

        config(['print.browser.locale' => 'ru']);
        $text = $this->visibleText($order);
        $this->assertStringContainsString('ПОЛУЧИТЬ С КЛИЕНТА', $text);
        $this->assertStringNotContainsString('MIJOZDAN OLINADI', $text);
    }

    // --- Hisob to'g'riligi (haqiqiy buyurtma oqimi) ---------------------------------------------------------

    private function customerWithAddress(): array
    {
        $user = User::factory()->create(['telegram_id' => 920001, 'full_name' => 'Ali Valiyev', 'phone' => '+998901112233', 'language' => 'ru']);
        $address = Address::factory()->for($user)->default()->create(['lat' => 40.7830, 'lng' => 72.3500]);

        return [$user, $address];
    }

    private function place(User $user, array $payload): Order
    {
        $id = $this->postJson('/api/orders', $payload, $this->initDataHeaders($this->signedInitData(['id' => $user->telegram_id])))
            ->assertCreated()->json('data.id');

        $order = Order::withoutGlobalScopes()->findOrFail($id);
        $this->owner->forceFill(['restaurant_id' => $order->restaurant_id])->save();

        return $order;
    }

    /** Qatorlar jami − chegirma (+ yetkazish) == MIJOZDAN OLINADI == order.total — chekning har ikki ko'rinishida. */
    private function assertReceiptMathMatchesPayment(Order $order): void
    {
        $data = app(ReceiptFormatter::class)->data($order->fresh(['user', 'restaurant']));

        $lines = array_sum(array_column($data->items, 'total'));
        $this->assertSame($lines, $data->itemsTotal, 'qatorlar jami = Taomlar');
        $this->assertSame(
            $order->total,
            $lines - $data->discount + $data->deliveryFee,
            'qatorlar jami − chegirma + yetkazish = mijozdan olinadigan summa (OrderService mantig‘i)',
        );

        $som = fn (int $tiyin) => ReceiptFormatter::amount($tiyin);
        $text = $this->visibleText($order);
        $this->assertStringContainsString('MIJOZDAN OLINADI: '.$som($order->total).' so‘m', $text);

        $escpos = $this->escpos($order);
        $this->assertMatchesRegularExpression('/MIJOZDAN OLINADI:\s+'.preg_quote($som($order->total), '/').' som$/m', $escpos);

        if ($data->hasBreakdown()) {
            $this->assertStringContainsString('Taomlar: '.$som($lines), $text);
            $this->assertMatchesRegularExpression('/^Taomlar:\s+'.preg_quote($som($lines), '/').' som$/m', $escpos);
        } else {
            $this->assertStringNotContainsString('Taomlar:', $text);
        }
        if ($data->discount > 0) {
            $this->assertStringContainsString('Chegirma: -'.$som($data->discount), $text);
            $this->assertMatchesRegularExpression('/^Chegirma:\s+-'.preg_quote($som($data->discount), '/').' som$/m', $escpos);
        } else {
            $this->assertStringNotContainsString('Chegirma:', $text);
        }
        if ($data->deliveryFee > 0) {
            $this->assertStringContainsString('Yetkazish: '.$som($data->deliveryFee), $text);
        } else {
            $this->assertStringNotContainsString('Yetkazish:', $text);
        }
    }

    public function test_delivery_order_with_a_promo_code_adds_up(): void
    {
        $cat = Category::factory()->for($this->restaurant)->create(['is_active' => true]);
        $osh = Product::factory()->for($cat)->create(['name' => 'Osh', 'price' => 3_200_000, 'is_available' => true]);
        $non = Product::factory()->for($cat)->create(['name' => 'Non', 'price' => 400_000, 'is_available' => true]);
        PromoCode::factory()->at($this->restaurant)->percent(25)->restaurantShare(50)->create(['code' => 'CHEGIRMA']);
        [$user, $address] = $this->customerWithAddress();

        $order = $this->place($user, [
            'restaurant_id' => $this->restaurant->id, 'delivery_type' => 'delivery', 'address_id' => $address->id,
            'items' => [['product_id' => $osh->id, 'qty' => 2], ['product_id' => $non->id, 'qty' => 3]],
            'promo_code' => 'CHEGIRMA',
        ]);

        // 2 x 32 000 + 3 x 4 000 = 76 000; chegirma 25% = 19 000; yetkazish 10 000 -> 67 000.
        $this->assertSame(7_600_000, $order->subtotal);
        $this->assertSame(1_900_000, $order->discount_amount);
        $this->assertSame(1_000_000, $order->delivery_fee);
        $this->assertSame(6_700_000, $order->total);
        $this->assertReceiptMathMatchesPayment($order);

        $text = $this->visibleText($order);
        $this->assertStringContainsString('2x Osh 64 000', $text);
        $this->assertStringContainsString('3x Non 12 000', $text);
        $this->assertStringContainsString('Taomlar: 76 000 Chegirma: -19 000 Yetkazish: 10 000', $text);
        $this->assertStringContainsString('MIJOZDAN OLINADI: 67 000 so‘m', $text);
    }

    public function test_pickup_order_without_a_discount_has_no_breakdown(): void
    {
        $cat = Category::factory()->for($this->restaurant)->create(['is_active' => true]);
        $osh = Product::factory()->for($cat)->create(['name' => 'Osh', 'price' => 3_200_000, 'is_available' => true]);
        [$user] = $this->customerWithAddress();

        $order = $this->place($user, [
            'restaurant_id' => $this->restaurant->id, 'delivery_type' => 'pickup',
            'items' => [['product_id' => $osh->id, 'qty' => 2]],
        ]);

        $this->assertSame(6_400_000, $order->total);
        $this->assertReceiptMathMatchesPayment($order);
        $this->assertStringNotContainsString('Taomlar:', $this->visibleText($order));
    }

    public function test_taxi_delivery_fee_is_not_added_and_a_short_note_stays(): void
    {
        $restaurant = $this->makeRestaurant([
            'name' => 'Taksi Resto', 'free_delivery_radius_km' => 0.01, 'price_per_km' => 200_000,
            'beyond_free_zone_delivery' => BeyondFreeZoneDelivery::Taxi,
        ]);
        $this->restaurant = $restaurant;
        $cat = Category::factory()->for($restaurant)->create(['is_active' => true]);
        $osh = Product::factory()->for($cat)->create(['name' => 'Osh', 'price' => 3_200_000, 'is_available' => true]);
        PromoCode::factory()->at($restaurant)->fixed(500_000)->restaurantShare(50)->create(['code' => 'BESHYUZ']);
        [$user, $address] = $this->customerWithAddress();

        $order = $this->place($user, [
            'restaurant_id' => $restaurant->id, 'delivery_type' => 'delivery', 'address_id' => $address->id,
            'items' => [['product_id' => $osh->id, 'qty' => 1]], 'promo_code' => 'BESHYUZ',
        ]);

        $this->assertTrue($order->delivery_paid_to_taxi);
        $this->assertSame(0, $order->delivery_fee);
        $this->assertSame(2_700_000, $order->total, 'faqat taomlar − chegirma; yetkazish haqi qo‘shilmaydi');
        $this->assertReceiptMathMatchesPayment($order);

        $text = $this->visibleText($order);
        $this->assertStringContainsString('Taomlar: 32 000 Chegirma: -5 000', $text);
        $this->assertStringNotContainsString('Yetkazish:', $text);
        $this->assertStringContainsString('Yetkazish haqi: mijoz taksiga alohida to‘laydi', $text);
        $this->assertStringContainsString('MIJOZDAN OLINADI: 27 000 so‘m', $text);
    }
}
