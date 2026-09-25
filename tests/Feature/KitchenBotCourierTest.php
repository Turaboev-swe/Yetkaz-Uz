<?php

namespace Tests\Feature;

use App\Enums\DeliveryType;
use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Jobs\NotifyCustomerOfStatusChange;
use App\Models\District;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\UpdateType;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

/**
 * Bot orqali "Yo'lga chiqdi": avval kuryer turi so'raladi
 * (kcourier -> kcourierown/kcouriertaxi -> kcourierpick / matn).
 */
class KitchenBotCourierTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_ID = 700700;

    private Restaurant $restaurant;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Event::fake([OrderStatusChanged::class]);
        Queue::fake();

        $this->restaurant = Restaurant::factory()->for(District::factory())->create();
        $this->staff = Staff::factory()->kitchenStaff($this->restaurant)
            ->withTelegramChatId(self::CHAT_ID)->create();
    }

    private function order(array $attrs = []): Order
    {
        return Order::factory()->for($this->restaurant)->for(User::factory())->create(array_replace([
            'status' => OrderStatus::Preparing,
            'delivery_type' => DeliveryType::Delivery,
        ], $attrs));
    }

    private function click(int $chatId, string $data, ?Nutgram $bot = null): Nutgram
    {
        $bot ??= app(Nutgram::class);
        $bot->hearUpdateType(UpdateType::CALLBACK_QUERY, [
            'from' => ['id' => $chatId, 'first_name' => 'X'],
            'data' => $data,
            'message' => [
                'message_id' => 50,
                'date' => 1703892479,
                'chat' => ['id' => $chatId, 'type' => 'private'],
            ],
        ])->reply();

        return $bot;
    }

    public function test_courier_button_shows_type_choice(): void
    {
        $order = $this->order();

        $bot = $this->click(self::CHAT_ID, "kcourier:{$order->id}:preparing");

        $bot->assertCalled('sendMessage');
        // 0: answerCallbackQuery, 1: tur tanlash xabari.
        $bot->assertRaw(fn ($r) => str_contains((string) $r->getBody(), 'kcourierown:'.$order->id)
            && str_contains((string) $r->getBody(), 'kcouriertaxi:'.$order->id), 1);
        $this->assertSame('preparing', $order->fresh()->status->value); // hali o'zgarmagan
    }

    public function test_own_staff_choice_lists_restaurant_staff(): void
    {
        Staff::factory()->kitchenStaff($this->restaurant)->create(['name' => 'Alisher']);
        $order = $this->order();

        $bot = $this->click(self::CHAT_ID, "kcourierown:{$order->id}:preparing");

        $bot->assertCalled('sendMessage');
        $bot->assertRaw(fn ($r) => str_contains((string) $r->getBody(), 'Alisher')
            && str_contains((string) $r->getBody(), "kcourierpick:{$order->id}:preparing:0"), 1);
    }

    public function test_picking_a_staff_member_advances_with_own_staff_type(): void
    {
        $courier = Staff::factory()->kitchenStaff($this->restaurant)
            ->withPhone('+998901112233')->create(['name' => 'Alisher']);
        $order = $this->order();

        $this->click(self::CHAT_ID, "kcourierpick:{$order->id}:preparing:{$courier->id}");

        $order->refresh();
        $this->assertSame('on_the_way', $order->status->value);
        $this->assertSame('own_staff', $order->courier_type->value);
        $this->assertSame('Alisher', $order->courier_name);
        $this->assertSame('+998901112233', $order->courier_phone);
        Queue::assertPushed(NotifyCustomerOfStatusChange::class);
    }

    public function test_picking_no_staff_advances_courierless_but_typed(): void
    {
        $order = $this->order();

        $this->click(self::CHAT_ID, "kcourierpick:{$order->id}:preparing:0");

        $order->refresh();
        $this->assertSame('on_the_way', $order->status->value);
        $this->assertSame('own_staff', $order->courier_type->value);
        $this->assertNull($order->courier_staff_id);
    }

    public function test_taxi_choice_asks_for_the_phone_number(): void
    {
        $order = $this->order();

        $bot = $this->click(self::CHAT_ID, "kcouriertaxi:{$order->id}:preparing");

        $bot->assertCalled('sendMessage');
        // 0: answerCallbackQuery, 1: suhbat boshlanishi (telefon so'raladi).
        $bot->assertRaw(fn ($r) => str_contains((string) $r->getBody(), 'Royal Taxi'), 1);
        $this->assertSame('preparing', $order->fresh()->status->value);
    }

    public function test_valid_phone_after_taxi_choice_advances_with_taxi_type(): void
    {
        $order = $this->order();

        $bot = app(Nutgram::class);
        $bot->willStartConversation();
        $this->click(self::CHAT_ID, "kcouriertaxi:{$order->id}:preparing", $bot);
        $bot->hearMessage(['from' => ['id' => self::CHAT_ID, 'first_name' => 'X'], 'text' => '+998 90 111 22 33'])->reply();

        $order->refresh();
        $this->assertSame('on_the_way', $order->status->value);
        $this->assertSame('taxi', $order->courier_type->value);
        $this->assertSame('Royal Taxi', $order->courier_name);
        $this->assertSame('+998901112233', $order->courier_phone);
        Queue::assertPushed(NotifyCustomerOfStatusChange::class);
        $bot->assertNoConversation();
    }

    /** Prefikssiz mahalliy raqam ("901112233") ham botda avtomatik +998 bilan to'ldiriladi. */
    public function test_bare_nine_digit_phone_is_auto_completed_with_998(): void
    {
        $order = $this->order();

        $bot = app(Nutgram::class);
        $bot->willStartConversation();
        $this->click(self::CHAT_ID, "kcouriertaxi:{$order->id}:preparing", $bot);
        $bot->hearMessage(['from' => ['id' => self::CHAT_ID, 'first_name' => 'X'], 'text' => '901112233'])->reply();

        $order->refresh();
        $this->assertSame('on_the_way', $order->status->value);
        $this->assertSame('+998901112233', $order->courier_phone);
    }

    public function test_invalid_phone_is_rejected_and_asked_again(): void
    {
        $order = $this->order();

        $bot = app(Nutgram::class);
        $bot->willStartConversation();
        $this->click(self::CHAT_ID, "kcouriertaxi:{$order->id}:preparing", $bot);
        $bot->hearMessage(['from' => ['id' => self::CHAT_ID, 'first_name' => 'X'], 'text' => '12345'])->reply(); // juda qisqa

        $bot->assertRaw(fn ($r) => str_contains((string) $r->getBody(), "noto'g'ri"));
        $this->assertSame('preparing', $order->fresh()->status->value); // hali o'zgarmagan

        // Qayta, to'g'ri raqam bilan — davom etadi.
        $bot->hearMessage(['from' => ['id' => self::CHAT_ID, 'first_name' => 'X'], 'text' => '+998901112233'])->reply();
        $this->assertSame('on_the_way', $order->fresh()->status->value);
        $bot->assertNoConversation();
    }

    /**
     * Oxirgi `$method` so'rovi (editMessageText / sendMessage): matni va
     * inline tugmalari ([text, callback_data] ro'yxati).
     *
     * @return array{text: string, buttons: list<array{text: string, callback_data: string}>}
     */
    private function lastKeyboardMessage(Nutgram $bot, string $method): array
    {
        $requests = array_filter(
            $bot->getRequestHistory(),
            fn (array $reqRes) => $reqRes['request']->getUri()->getPath() === $method,
        );
        $this->assertNotEmpty($requests, "$method chaqirilmadi");

        $data = FakeNutgram::getActualData(end($requests)['request']);
        $markup = is_string($data['reply_markup'] ?? null)
            ? json_decode($data['reply_markup'], true)
            : ($data['reply_markup'] ?? []);

        return [
            'text' => (string) ($data['text'] ?? ''),
            'buttons' => array_merge(...($markup['inline_keyboard'] ?? [[]])),
        ];
    }

    private function assertDeliveredButton(array $message, Order $order): void
    {
        $this->assertContains(
            ['text' => '✅ Yetkazildi', 'callback_data' => "kadv:{$order->id}:on_the_way"],
            array_map(fn (array $b) => ['text' => $b['text'], 'callback_data' => $b['callback_data'] ?? null], $message['buttons']),
            'Advance\'dan keyin "✅ Yetkazildi" tugmasi chiqmadi',
        );
    }

    public function test_own_courier_flow_shows_delivered_button_after_advancing(): void
    {
        $courier = Staff::factory()->kitchenStaff($this->restaurant)->create(['name' => 'Alisher']);
        $order = $this->order();

        $bot = $this->click(self::CHAT_ID, "kcourierpick:{$order->id}:preparing:{$courier->id}");

        // Xodim tanlash xabari joyida yangilanadi — matn + "Yetkazildi" tugmasi.
        $message = $this->lastKeyboardMessage($bot, 'editMessageText');
        $this->assertSame("🛵 Yo'lga chiqdi — {$order->order_number}. Kuryer: Alisher", $message['text']);
        $this->assertDeliveredButton($message, $order);

        // Tugma haqiqatan ishlaydi: bosilsa buyurtma yetkazildi.
        $this->click(self::CHAT_ID, "kadv:{$order->id}:on_the_way");
        $this->assertSame('delivered', $order->fresh()->status->value);
    }

    public function test_royal_taxi_flow_shows_delivered_button_after_advancing(): void
    {
        $order = $this->order();

        $bot = app(Nutgram::class);
        $bot->willStartConversation();
        $this->click(self::CHAT_ID, "kcouriertaxi:{$order->id}:preparing", $bot);
        $bot->hearMessage(['from' => ['id' => self::CHAT_ID, 'first_name' => 'X'], 'text' => '901112233'])->reply();

        // Telefon — matn xabari, tahrirlanmaydi: tugma yangi xabarda keladi.
        $message = $this->lastKeyboardMessage($bot, 'sendMessage');
        $this->assertSame("🚕 Yo'lga chiqdi — {$order->order_number}. Royal Taxi: +998901112233", $message['text']);
        $this->assertDeliveredButton($message, $order);

        $this->click(self::CHAT_ID, "kadv:{$order->id}:on_the_way");
        $this->assertSame('delivered', $order->fresh()->status->value);
    }

    public function test_foreign_restaurant_staff_cannot_pick_a_courier(): void
    {
        $otherRestaurant = Restaurant::factory()->for(District::factory())->create();
        Staff::factory()->kitchenStaff($otherRestaurant)->withTelegramChatId(800800)->create();

        $order = $this->order();
        $this->click(800800, "kcourier:{$order->id}:preparing");

        $this->assertSame('preparing', $order->fresh()->status->value);
    }
}
