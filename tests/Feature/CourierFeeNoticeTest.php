<?php

namespace Tests\Feature;

use App\Enums\CourierType;
use App\Enums\DeliveryType;
use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Http\Resources\KitchenOrderResource;
use App\Jobs\NotifyCustomerOfStatusChange;
use App\Models\District;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Models\User;
use App\Services\Dispatch\ReceiptFormatter;
use App\Services\Ordering\OrderStatusService;
use App\Telegram\Support\KitchenOrderMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\UpdateType;
use Tests\TestCase;

/**
 * Taksi rejimi + kuryer tanlovi: mijoz to'lovi buyurtma paytida belgilanadi
 * (delivery_fee / delivery_paid_to_taxi snapshot), kuryer tanlovi uni O'ZGARTIRMAYDI.
 * Faqat mijozga xabar va oshxona xodimiga ogohlantirish farqlanadi.
 */
class CourierFeeNoticeTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_ID = 700801;

    private Restaurant $restaurant;

    private Staff $staff;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Event::fake([OrderStatusChanged::class]);

        $this->restaurant = Restaurant::factory()->for(District::factory())->create(['name' => 'Osh Markazi']);
        $this->staff = Staff::factory()->kitchenStaff($this->restaurant)->withTelegramChatId(self::CHAT_ID)->create();
        $this->customer = User::factory()->create(['language' => 'uz']);
    }

    /** @param array<string, mixed> $attrs */
    private function order(string $kind, array $attrs = []): Order
    {
        $base = match ($kind) {
            // Royal Taxi rejimi, bepul zonadan tashqarida: narx 0, belgi true.
            'taxi_beyond' => ['delivery_fee' => 0, 'delivery_paid_to_taxi' => true],
            // Royal Taxi rejimi, bepul zona ichida: 0, belgisiz.
            'taxi_free' => ['delivery_fee' => 0, 'delivery_paid_to_taxi' => false],
            // per_km restoran: mijoz yetkazish haqini ilovada to'lagan.
            'per_km' => ['delivery_fee' => 1_500_000, 'delivery_paid_to_taxi' => false],
        };

        $subtotal = 3_200_000;

        return Order::factory()->for($this->restaurant)->for($this->customer)->create(array_replace([
            'status' => OrderStatus::Preparing,
            'delivery_type' => DeliveryType::Delivery,
            'subtotal' => $subtotal,
            'total' => $subtotal + $base['delivery_fee'],
            'address_snapshot' => ['address_text' => 'Navoi 1', 'lat' => 40.78, 'lng' => 72.35],
        ], $base, $attrs));
    }

    // --- Yordamchilar --------------------------------------------------------------------

    private function bodies(Nutgram $bot): string
    {
        return collect($bot->getRequestHistory())
            ->map(fn (array $r) => (string) $r['request']->getBody())
            ->implode("\n");
    }

    /** "Yo'lga chiqdi" xabari (mijozga). */
    private function onTheWayMessage(Order $order, array $courier): string
    {
        $order->update(array_merge(['status' => OrderStatus::OnTheWay], $courier));

        $this->app->forgetInstance(Nutgram::class);
        $bot = app(Nutgram::class);
        (new NotifyCustomerOfStatusChange($order->id))->handle($bot);

        return $this->bodies($bot);
    }

    private const TAXI = ['courier_type' => CourierType::Taxi, 'courier_name' => 'Royal Taxi', 'courier_phone' => '+998907778899'];

    private const OWN = ['courier_type' => CourierType::OwnStaff, 'courier_name' => 'Alisher', 'courier_phone' => '+998901112233'];

    private const NONE = ['courier_type' => CourierType::OwnStaff, 'courier_name' => null, 'courier_phone' => null];

    private function click(string $data): Nutgram
    {
        $this->app->forgetInstance(Nutgram::class);
        $bot = app(Nutgram::class);
        $bot->hearUpdateType(UpdateType::CALLBACK_QUERY, [
            'from' => ['id' => self::CHAT_ID, 'first_name' => 'X'],
            'data' => $data,
            'message' => ['message_id' => 50, 'date' => 1703892479, 'chat' => ['id' => self::CHAT_ID, 'type' => 'private']],
        ])->reply();

        return $bot;
    }

    // --- 1. "Yo'lga chiqdi" xabari ----------------------------------------------------------

    public function test_taxi_order_with_royal_taxi_keeps_the_taxi_fee_note(): void
    {
        $text = $this->onTheWayMessage($this->order('taxi_beyond'), self::TAXI);

        $this->assertStringContainsString('Royal Taxi orqali yo‘lga chiqdi', $text);
        $this->assertStringContainsString('Yetkazish haqini taksi haydovchisiga to‘laysiz.', $text);
        $this->assertStringNotContainsString('shart emas', $text);
    }

    public function test_taxi_order_with_own_courier_says_no_payment_needed_and_never_mentions_taxi(): void
    {
        $text = $this->onTheWayMessage($this->order('taxi_beyond'), self::OWN);

        $this->assertStringContainsString('kuryerimiz bilan yo‘lga chiqdi', $text);
        $this->assertStringContainsString('Kuryer: Alisher — +998901112233', $text);
        $this->assertStringContainsString('Yetkazish uchun to‘lov qilish shart emas.', $text);
        $this->assertStringNotContainsString('taksi', mb_strtolower($text));
        $this->assertStringNotContainsString('Royal', $text);
    }

    public function test_taxi_order_without_courier_still_says_no_payment_needed(): void
    {
        $text = $this->onTheWayMessage($this->order('taxi_beyond'), self::NONE);

        $this->assertStringContainsString('yo‘lga chiqdi', $text);
        $this->assertStringContainsString('Yetkazish uchun to‘lov qilish shart emas.', $text);
        $this->assertStringNotContainsString('taksi', mb_strtolower($text));
    }

    public function test_per_km_order_with_royal_taxi_tells_the_customer_nothing_about_payment(): void
    {
        $text = $this->onTheWayMessage($this->order('per_km'), self::TAXI);

        $this->assertStringContainsString('Royal Taxi orqali yo‘lga chiqdi', $text);
        $this->assertStringNotContainsString('to‘laysiz', $text);
        $this->assertStringNotContainsString('shart emas', $text);
    }

    public function test_free_zone_order_with_royal_taxi_tells_the_customer_nothing_about_payment(): void
    {
        $text = $this->onTheWayMessage($this->order('taxi_free'), self::TAXI);

        $this->assertStringNotContainsString('to‘laysiz', $text);
        $this->assertStringNotContainsString('shart emas', $text);
    }

    public function test_non_flagged_orders_with_own_courier_are_unchanged(): void
    {
        foreach (['per_km', 'taxi_free'] as $kind) {
            $text = $this->onTheWayMessage($this->order($kind), self::OWN);

            $this->assertStringContainsString('Alisher', $text);
            $this->assertStringNotContainsString('shart emas', $text, $kind);
            $this->assertStringNotContainsString('kuryerimiz bilan', $text, $kind);
        }
    }

    public function test_own_courier_message_in_russian(): void
    {
        $this->customer->update(['language' => 'ru']);

        $text = $this->onTheWayMessage($this->order('taxi_beyond'), self::OWN);

        $this->assertStringContainsString('с нашим курьером', $text);
        $this->assertStringContainsString('Курьер: Alisher — +998901112233', $text);
        $this->assertStringContainsString('Оплачивать доставку не нужно.', $text);
        $this->assertStringNotContainsString('такси', mb_strtolower($text));
    }

    // --- 2. Kuryer tanlash ogohlantirishlari (bot) --------------------------------------------

    public function test_bot_warns_when_own_courier_is_chosen_for_a_taxi_order(): void
    {
        $order = $this->order('taxi_beyond');

        $text = $this->bodies($this->click("kcourierown:{$order->id}:preparing"));

        $this->assertStringContainsString('Bu buyurtmada yetkazish mijoz uchun bepul — kuryer mijozdan pul olmasin', $text);
    }

    public function test_bot_does_not_warn_own_courier_for_orders_where_the_customer_pays_normally(): void
    {
        foreach (['per_km', 'taxi_free'] as $kind) {
            $order = $this->order($kind);

            $this->assertStringNotContainsString('mijozdan pul olmasin', $this->bodies($this->click("kcourierown:{$order->id}:preparing")), $kind);
        }
    }

    public function test_bot_warns_when_royal_taxi_is_chosen_for_a_prepaid_per_km_order(): void
    {
        $order = $this->order('per_km');

        $text = $this->bodies($this->click("kcouriertaxi:{$order->id}:preparing"));

        $this->assertStringContainsString('Mijoz yetkazish haqini ilovada to‘lagan — taksi haqini restoran to‘laydi, mijozdan olmang', $text);
    }

    public function test_bot_does_not_warn_taxi_when_customer_did_not_pay_delivery(): void
    {
        foreach (['taxi_beyond', 'taxi_free'] as $kind) {
            $order = $this->order($kind);

            $this->assertStringNotContainsString('mijozdan olmang', $this->bodies($this->click("kcouriertaxi:{$order->id}:preparing")), $kind);
        }
    }

    public function test_warning_helper_covers_every_case(): void
    {
        $taxi = $this->order('taxi_beyond');
        $perKm = $this->order('per_km');
        $free = $this->order('taxi_free');

        $this->assertNotNull(KitchenOrderMessage::courierWarning($taxi, 'own'));
        $this->assertNull(KitchenOrderMessage::courierWarning($taxi, 'taxi'));
        $this->assertNull(KitchenOrderMessage::courierWarning($perKm, 'own'));
        $this->assertNotNull(KitchenOrderMessage::courierWarning($perKm, 'taxi'));
        $this->assertNull(KitchenOrderMessage::courierWarning($free, 'own'));
        $this->assertNull(KitchenOrderMessage::courierWarning($free, 'taxi'));
    }

    // --- 3. "Mijozdan olinadi" kuryer tanloviga bog'liq emas ------------------------------------

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function courierChoices(): array
    {
        return [
            'taxi_beyond + Royal Taxi' => ['taxi_beyond', self::TAXI],
            'taxi_beyond + o‘z kuryer' => ['taxi_beyond', self::OWN],
            'taxi_beyond + kuryersiz' => ['taxi_beyond', self::NONE],
            'per_km + Royal Taxi' => ['per_km', self::TAXI],
            'per_km + o‘z kuryer' => ['per_km', self::OWN],
            'taxi_free + Royal Taxi' => ['taxi_free', self::TAXI],
        ];
    }

    /** @param  array<string, mixed>  $courier */
    #[\PHPUnit\Framework\Attributes\DataProvider('courierChoices')]
    public function test_amount_to_collect_does_not_depend_on_the_courier_choice(string $kind, array $courier): void
    {
        Queue::fake();
        $order = $this->order($kind);
        $expected = $order->total; // buyurtma paytidagi summa (chekdagi)

        $message = app(KitchenOrderMessage::class);
        $line = fn (Order $o): string => collect(explode("\n", $message->text($o->fresh(['user', 'restaurant']))))->first(fn ($l) => str_contains($l, 'Mijozdan olinadi'));
        $flat = fn (Order $o): string => preg_replace('/\s+/', ' ', app(ReceiptFormatter::class)->format($o->fresh(['user', 'restaurant']))['text']);
        $card = fn (Order $o): array => (new KitchenOrderResource($o->fresh(['user', 'restaurant'])))->toArray(Request::create('/'));

        $before = [$line($order), $flat($order), $card($order)['total'], $card($order)['delivery_fee'], $card($order)['delivery_paid_to_taxi']];

        app(OrderStatusService::class)->advance($order, 'test', $courier);

        $order->refresh();
        $this->assertSame(OrderStatus::OnTheWay, $order->status);
        $this->assertSame($expected, $order->total);
        $this->assertSame($before, [$line($order), $flat($order), $card($order)['total'], $card($order)['delivery_fee'], $card($order)['delivery_paid_to_taxi']]);
        $this->assertStringContainsString(number_format(intdiv($expected, 100), 0, '.', ' '), $line($order));
    }

    public function test_kitchen_card_payload_still_exposes_the_snapshot_for_the_panel_warnings(): void
    {
        $card = fn (Order $o): array => (new KitchenOrderResource($o->load(['user', 'restaurant'])))->toArray(Request::create('/'));

        $taxi = $card($this->order('taxi_beyond'));
        $perKm = $card($this->order('per_km'));

        $this->assertTrue($taxi['delivery_paid_to_taxi']);
        $this->assertSame(0, $taxi['delivery_fee']);
        $this->assertFalse($perKm['delivery_paid_to_taxi']);
        $this->assertGreaterThan(0, $perKm['delivery_fee']);
    }
}
