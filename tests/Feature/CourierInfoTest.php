<?php

namespace Tests\Feature;

use App\Enums\DeliveryType;
use App\Enums\OrderStatus;
use App\Jobs\NotifyCustomerOfStatusChange;
use App\Models\District;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\UpdateType;
use Tests\TestCase;

class CourierInfoTest extends TestCase
{
    use RefreshDatabase;

    private const RESTAURANT_PHONE = '+998711234567';

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Queue::fake();
        $this->restaurant = Restaurant::factory()->for(District::factory())->create(['phone' => self::RESTAURANT_PHONE]);
    }

    /** @param array<string, mixed> $attrs */
    private function order(array $attrs = []): Order
    {
        return Order::factory()
            ->for($this->restaurant)
            ->for(User::factory(['telegram_id' => 900_001, 'language' => 'uz']))
            ->create(array_replace([
                'status' => OrderStatus::OnTheWay,
                'delivery_type' => DeliveryType::Delivery,
            ], $attrs));
    }

    private function customerMessage(Order $order): string
    {
        $bot = app(Nutgram::class);
        (new NotifyCustomerOfStatusChange($order->id))->handle($bot);

        foreach ($bot->getRequestHistory() as $entry) {
            [$request] = array_values($entry);
            if (str_ends_with($request->getUri()->getPath(), 'sendMessage')) {
                return (string) $request->getBody();
            }
        }

        $this->fail('sendMessage chaqirilmadi');
    }

    // --- NotifyCustomerOfStatusChange: on_the_way xabari ---

    public function test_courier_name_and_phone_are_shown_when_set(): void
    {
        $body = $this->customerMessage($this->order([
            'courier_name' => 'Alisher',
            'courier_phone' => '+998901112233',
        ]));

        $this->assertStringContainsString('Kuryer', $body);
        $this->assertStringContainsString('Alisher', $body);
        $this->assertStringContainsString('+998901112233', $body);
        $this->assertStringNotContainsString(self::RESTAURANT_PHONE, $body);
    }

    public function test_falls_back_to_restaurant_phone_when_no_courier(): void
    {
        $body = $this->customerMessage($this->order());

        $this->assertStringContainsString(self::RESTAURANT_PHONE, $body);
        $this->assertStringContainsString('Restoran', $body);
        $this->assertStringNotContainsString('Kuryer', $body);
    }

    public function test_only_courier_name_is_shown(): void
    {
        $body = $this->customerMessage($this->order(['courier_name' => 'Alisher']));

        $this->assertStringContainsString('Kuryer', $body);
        $this->assertStringContainsString('Alisher', $body);
        $this->assertStringNotContainsString(self::RESTAURANT_PHONE, $body);
    }

    public function test_only_courier_phone_is_shown(): void
    {
        $body = $this->customerMessage($this->order(['courier_phone' => '+998901112233']));

        $this->assertStringContainsString('+998901112233', $body);
        $this->assertStringNotContainsString('Kuryer', $body);
        $this->assertStringNotContainsString(self::RESTAURANT_PHONE, $body);
    }

    // --- /kitchen advance ---

    public function test_kitchen_advance_saves_courier_info_then_changes_status(): void
    {
        $owner = Staff::factory()->owner($this->restaurant)->create();
        $order = $this->order(['status' => OrderStatus::Preparing]);

        $this->actingAs($owner, 'staff')
            ->patchJson("/kitchen/orders/{$order->id}/advance", [
                'courier_name' => '  Alisher  ',
                'courier_phone' => '+998901112233',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'on_the_way');

        $order->refresh();
        $this->assertSame('Alisher', $order->courier_name);
        $this->assertSame('+998901112233', $order->courier_phone);
        Queue::assertPushed(NotifyCustomerOfStatusChange::class);
    }

    public function test_kitchen_advance_without_courier_leaves_fields_null(): void
    {
        $owner = Staff::factory()->owner($this->restaurant)->create();
        $order = $this->order(['status' => OrderStatus::Preparing]);

        $this->actingAs($owner, 'staff')
            ->patchJson("/kitchen/orders/{$order->id}/advance", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'on_the_way');

        $order->refresh();
        $this->assertNull($order->courier_name);
        $this->assertNull($order->courier_phone);
    }

    // --- bot: hozircha kuryer so'ralmaydi ---

    public function test_bot_advance_leaves_courier_empty_and_message_uses_restaurant_phone(): void
    {
        $staff = Staff::factory()->kitchenStaff($this->restaurant)->withTelegramChatId(770077)->create();
        $order = $this->order(['status' => OrderStatus::Preparing]);

        $bot = app(Nutgram::class);
        $bot->hearUpdateType(UpdateType::CALLBACK_QUERY, [
            'from' => ['id' => 770077, 'first_name' => 'X'],
            'data' => "kadv:{$order->id}:preparing",
            'message' => ['message_id' => 40, 'date' => 1703892479, 'chat' => ['id' => 770077, 'type' => 'private']],
        ])->reply();

        $order->refresh();
        $this->assertSame('on_the_way', $order->status->value);
        $this->assertNull($order->courier_name);
        $this->assertNull($order->courier_phone);

        $this->assertStringContainsString(self::RESTAURANT_PHONE, $this->customerMessage($order));
    }
}
