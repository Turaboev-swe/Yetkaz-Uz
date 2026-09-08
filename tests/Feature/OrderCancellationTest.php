<?php

namespace Tests\Feature;

use App\Enums\DeliveryType;
use App\Enums\OrderStatus;
use App\Jobs\NotifyCustomerOfCancellation;
use App\Models\District;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Models\User;
use App\Services\Ordering\OrderStatusService;
use App\Telegram\Support\KitchenOrderMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\UpdateType;
use Tests\TestCase;

class OrderCancellationTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_ID = 717171;

    private Restaurant $restaurant;

    private Staff $owner;

    private Staff $kitchen;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        $this->restaurant = Restaurant::factory()->for(District::factory())->create();
        $this->owner = Staff::factory()->owner($this->restaurant)->create();
        $this->kitchen = Staff::factory()->kitchenStaff($this->restaurant)
            ->withTelegramChatId(self::CHAT_ID)->create();
    }

    private function order(array $attrs = []): Order
    {
        return Order::factory()->for($this->restaurant)->for(User::factory())->create(array_replace([
            'status' => OrderStatus::Accepted,
            'delivery_type' => DeliveryType::Delivery,
        ], $attrs));
    }

    private function click(int $chatId, string $data): Nutgram
    {
        $bot = app(Nutgram::class);
        $bot->hearUpdateType(UpdateType::CALLBACK_QUERY, [
            'from' => ['id' => $chatId, 'first_name' => 'X'],
            'data' => $data,
            'message' => ['message_id' => 40, 'date' => 1703892479, 'chat' => ['id' => $chatId, 'type' => 'private']],
        ])->reply();

        return $bot;
    }

    // --- Servis ---------------------------------------------------------

    public function test_cancel_from_accepted_sets_status_reason_and_history(): void
    {
        Queue::fake();
        $order = $this->order(['status' => OrderStatus::Accepted]);

        app(OrderStatusService::class)->cancel($order, 'Taom tugab qoldi', 'kitchen:5');

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertNotNull($order->cancelled_at);
        $this->assertSame('Taom tugab qoldi', $order->cancellation_reason);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id, 'status' => 'cancelled', 'changed_by' => 'kitchen:5',
        ]);
        Queue::assertPushed(NotifyCustomerOfCancellation::class, fn ($j) => $j->orderId === $order->id);
    }

    public function test_cancel_from_preparing_works(): void
    {
        Queue::fake();
        $order = $this->order(['status' => OrderStatus::Preparing]);

        app(OrderStatusService::class)->cancel($order, 'Band', 'kitchen:5');

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
    }

    public function test_cannot_cancel_from_on_the_way(): void
    {
        $order = $this->order(['status' => OrderStatus::OnTheWay]);

        $this->expectException(ValidationException::class);
        app(OrderStatusService::class)->cancel($order, 'kech', 'kitchen:5');
    }

    public function test_cannot_cancel_from_delivered(): void
    {
        $order = $this->order(['status' => OrderStatus::Delivered]);

        try {
            app(OrderStatusService::class)->cancel($order, 'kech', 'kitchen:5');
            $this->fail('ValidationException kutilgan edi');
        } catch (ValidationException $e) {
            $this->assertSame(OrderStatus::Delivered, $order->refresh()->status);
        }
    }

    public function test_empty_reason_is_rejected(): void
    {
        $order = $this->order(['status' => OrderStatus::Accepted]);

        $this->expectException(ValidationException::class);
        app(OrderStatusService::class)->cancel($order, '   ', 'kitchen:5');
    }

    // --- /kitchen paneli ----------------------------------------------

    public function test_panel_cancels_an_order(): void
    {
        Queue::fake();
        $order = $this->order(['status' => OrderStatus::Preparing]);

        $this->actingAs($this->kitchen, 'staff')
            ->patchJson("/kitchen/orders/{$order->id}/cancel", ['reason' => 'Restoran hozir band'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame('Restoran hozir band', $order->refresh()->cancellation_reason);
    }

    public function test_panel_rejects_cancel_from_wrong_status(): void
    {
        $order = $this->order(['status' => OrderStatus::OnTheWay]);

        $this->actingAs($this->kitchen, 'staff')
            ->patchJson("/kitchen/orders/{$order->id}/cancel", ['reason' => 'kech'])
            ->assertStatus(422);
    }

    public function test_panel_rejects_foreign_restaurant_staff(): void
    {
        $foreign = Order::factory()
            ->for(Restaurant::factory()->for(District::factory()))
            ->for(User::factory())
            ->create(['status' => OrderStatus::Accepted, 'delivery_type' => DeliveryType::Delivery]);

        $this->actingAs($this->kitchen, 'staff')
            ->patchJson("/kitchen/orders/{$foreign->id}/cancel", ['reason' => 'test'])
            ->assertStatus(403);
    }

    public function test_panel_requires_a_reason(): void
    {
        $order = $this->order(['status' => OrderStatus::Accepted]);

        $this->actingAs($this->kitchen, 'staff')
            ->patchJson("/kitchen/orders/{$order->id}/cancel", ['reason' => ''])
            ->assertStatus(422);
    }

    // --- Bot ---------------------------------------------------------

    public function test_bot_preset_reason_cancels_via_the_same_service(): void
    {
        Queue::fake();
        $order = $this->order(['status' => OrderStatus::Accepted]);

        $this->click(self::CHAT_ID, "kcreason:{$order->id}:out");

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(__('messages.kitchen_bot.reason_out', [], 'uz'), $order->cancellation_reason);
        Queue::assertPushed(NotifyCustomerOfCancellation::class);
    }

    public function test_bot_other_reason_is_collected_from_the_next_message(): void
    {
        Queue::fake();
        $order = $this->order(['status' => OrderStatus::Preparing]);

        $bot = app(Nutgram::class);
        $bot->willStartConversation();
        $bot->hearUpdateType(UpdateType::CALLBACK_QUERY, [
            'from' => ['id' => self::CHAT_ID, 'first_name' => 'X'],
            'data' => "kcreason:{$order->id}:other",
            'message' => ['message_id' => 40, 'date' => 1703892479, 'chat' => ['id' => self::CHAT_ID, 'type' => 'private']],
        ])->reply();
        // 0: answerCallbackQuery, 1: suhbat sendMessage
        $bot->assertReplyText(__('messages.kitchen_bot.cancel_ask_reason', [], 'uz'), 1);

        $bot->hearMessage(['from' => ['id' => self::CHAT_ID, 'first_name' => 'X'], 'text' => 'Kuryer yo\'q'])->reply();

        $this->assertSame('Kuryer yo\'q', $order->refresh()->cancellation_reason);
        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $bot->assertNoConversation();
    }

    public function test_bot_cancel_rejects_foreign_staff(): void
    {
        $order = $this->order(['status' => OrderStatus::Accepted]);

        $this->click(999888, "kcreason:{$order->id}:out"); // noma'lum chat

        $this->assertSame(OrderStatus::Accepted, $order->refresh()->status);
    }

    public function test_bot_cancel_button_shown_only_for_cancellable_orders(): void
    {
        $msg = app(KitchenOrderMessage::class);

        $cancellable = $this->order(['status' => OrderStatus::Preparing]);
        $notCancellable = $this->order(['status' => OrderStatus::OnTheWay]);

        $this->assertStringContainsString('kcancel:', json_encode($msg->keyboard($cancellable)));
        $this->assertStringNotContainsString('kcancel:', json_encode($msg->keyboard($notCancellable)));
    }

    // --- Mijoz xabari ----------------------------------------------

    public function test_customer_message_contains_the_reason(): void
    {
        $order = $this->order([
            'status' => OrderStatus::Cancelled,
            'cancellation_reason' => 'Taom tugab qoldi',
        ]);
        $order->user->update(['telegram_id' => 5550001, 'language' => 'uz']);

        $bot = app(Nutgram::class);
        (new NotifyCustomerOfCancellation($order->id))->handle($bot);

        $bot->assertCalled('sendMessage');
        $bot->assertReplyText(__('messages.order_notify.cancelled_full', [
            'n' => $order->order_number,
            'reason' => 'Taom tugab qoldi',
        ], 'uz'));
    }
}
