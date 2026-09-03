<?php

namespace Tests\Feature;

use App\Enums\DeliveryType;
use App\Enums\OrderStatus;
use App\Events\OrderPlaced;
use App\Events\OrderStatusChanged;
use App\Jobs\NotifyCustomerOfStatusChange;
use App\Listeners\NotifyKitchenStaffOfNewOrder;
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
use Tests\TestCase;

class KitchenBotCallbackTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Staff $staff;

    private const CHAT_ID = 700700;

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

    /** @param array<string, mixed> $attrs */
    private function order(array $attrs = []): Order
    {
        return Order::factory()->for($this->restaurant)->for(User::factory())->create(array_replace([
            'status' => OrderStatus::New,
            'delivery_type' => DeliveryType::Delivery,
        ], $attrs));
    }

    private function click(int $chatId, string $data): Nutgram
    {
        $bot = app(Nutgram::class);

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

    public function test_correct_staff_advances_the_status(): void
    {
        $order = $this->order();

        $bot = $this->click(self::CHAT_ID, "kadv:{$order->id}:new");

        $this->assertSame('accepted', $order->fresh()->status->value);
        $bot->assertCalled('answerCallbackQuery');
        $bot->assertCalled('editMessageText');
        Queue::assertPushed(NotifyCustomerOfStatusChange::class);
    }

    public function test_full_delivery_flow_via_buttons(): void
    {
        $order = $this->order();

        foreach (['new', 'accepted', 'preparing', 'on_the_way'] as $from) {
            $this->click(self::CHAT_ID, "kadv:{$order->id}:{$from}");
        }

        $this->assertSame('delivered', $order->fresh()->status->value);
        $this->assertNotNull($order->fresh()->delivered_at);
        $this->assertSame(4, $order->statusHistory()->count());
    }

    public function test_foreign_restaurant_staff_is_rejected(): void
    {
        $otherRestaurant = Restaurant::factory()->for(District::factory())->create();
        Staff::factory()->kitchenStaff($otherRestaurant)->withTelegramChatId(800800)->create();

        $order = $this->order();
        $bot = $this->click(800800, "kadv:{$order->id}:new");

        $this->assertSame('new', $order->fresh()->status->value);
        $bot->assertCalled('answerCallbackQuery');
        $bot->assertCalled('editMessageText', 0);
    }

    public function test_staff_without_kitchen_access_is_rejected(): void
    {
        Staff::factory()->platformAdmin()->withTelegramChatId(900900)->create();

        $order = $this->order();
        $bot = $this->click(900900, "kadv:{$order->id}:new");

        $this->assertSame('new', $order->fresh()->status->value);
        $bot->assertCalled('answerCallbackQuery');
        $bot->assertCalled('editMessageText', 0);
    }

    public function test_unknown_sender_is_rejected(): void
    {
        $order = $this->order();
        $bot = $this->click(111222, "kadv:{$order->id}:new");

        $this->assertSame('new', $order->fresh()->status->value);
        $bot->assertCalled('answerCallbackQuery');
    }

    public function test_finished_order_button_shows_message_without_error(): void
    {
        $order = $this->order(['status' => OrderStatus::Delivered]);

        $bot = $this->click(self::CHAT_ID, "kadv:{$order->id}:preparing");

        $this->assertSame('delivered', $order->fresh()->status->value);
        $bot->assertCalled('answerCallbackQuery');
        $bot->assertCalled('editMessageText'); // xabar joriy holatga yangilanadi
    }

    public function test_stale_button_does_not_double_advance(): void
    {
        // Boshqa xodim allaqachon "accepted" ga o'tkazgan.
        $order = $this->order(['status' => OrderStatus::Accepted]);

        $bot = $this->click(self::CHAT_ID, "kadv:{$order->id}:new");

        $this->assertSame('accepted', $order->fresh()->status->value);
        $bot->assertCalled('answerCallbackQuery');
    }

    public function test_listener_notifies_only_staff_with_chat_id(): void
    {
        Staff::factory()->kitchenStaff($this->restaurant)->create();            // chat_id yo'q — o'tkazib yuboriladi
        Staff::factory()->owner($this->restaurant)->withTelegramChatId(500500)->create();
        Staff::factory()->kitchenStaff($this->restaurant)->inactive()
            ->withTelegramChatId(600600)->create();                            // faol emas — o'tkazib yuboriladi

        $order = $this->order();
        (new NotifyKitchenStaffOfNewOrder)->handle(new OrderPlaced($order->id, $order->restaurant_id));

        // self::staff (kitchen, chat 700700) + owner (chat 500500) = 2 ta
        app(Nutgram::class)->assertCalled('sendMessage', 2);
    }

    public function test_order_placed_has_exactly_one_kitchen_notify_listener(): void
    {
        // Ikki marta ro'yxatga olinsa (auto-discovery + Event::listen) — bitta
        // buyurtma uchun ikkita xabar ketardi.
        $listeners = app('events')->getListeners(OrderPlaced::class);

        $this->assertCount(1, $listeners);
    }

    public function test_message_shows_readable_address_not_raw_coordinates(): void
    {
        $order = $this->order([
            'address_snapshot' => [
                'address_text' => '40.716863, 72.768369',
                'district' => 'Qo‘rg‘ontepa tumani',
                'label' => 'Uy',
                'lat' => 40.716863,
                'lng' => 72.768369,
            ],
        ]);

        (new NotifyKitchenStaffOfNewOrder)->handle(new OrderPlaced($order->id, $order->restaurant_id));

        $bot = app(Nutgram::class);
        $body = '';
        $bot->assertRaw(function ($request) use (&$body) {
            $body = (string) $request->getBody();

            return true;
        });

        $this->assertStringContainsString('Qo‘rg‘ontepa tumani', $body);
        $this->assertStringNotContainsString('40.716863, 72.768369', $body);
        $this->assertStringContainsString('maps.google.com', $body); // xarita havolasi
    }
}
