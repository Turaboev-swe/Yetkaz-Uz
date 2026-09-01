<?php

namespace Tests\Feature;

use App\Jobs\NotifyRestaurantOfNewOrder;
use App\Models\Address;
use App\Models\Category;
use App\Models\District;
use App\Models\Order;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Ordering\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use SergiX44\Nutgram\Nutgram;
use Tests\TestCase;

class OrderNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Queue::fake(); // job'ni place() ichida ishga tushirmaymiz — alohida sinaymiz
        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00', 'Asia/Tashkent'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(array $restaurantAttrs = [], string $type = 'delivery'): Order
    {
        $always = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], [['00:00', '23:59']]);
        $restaurant = Restaurant::factory()->for(District::factory())->create(array_replace([
            'name' => 'Test Resto', 'lat' => 40.78, 'lng' => 72.35,
            'is_open' => true, 'work_hours' => $always,
            'delivery_radius_km' => 8, 'delivery_fee' => 1_000_000, 'min_order_amount' => 1,
        ], $restaurantAttrs));

        $cat = Category::factory()->for($restaurant)->create(['is_active' => true]);
        $product = Product::factory()->for($cat)->create(['name' => 'Osh', 'price' => 3_200_000, 'is_available' => true]);

        $user = User::factory()->create([
            'full_name' => 'Ali Valiyev', 'phone' => '+998901112233', 'username' => 'ali_v',
        ]);
        $address = Address::factory()->for($user)->default()->create([
            'lat' => 40.781, 'lng' => 72.351, 'address_text' => 'Bobur 12', 'entrance' => '2',
        ]);

        return app(OrderService::class)->place($user, [
            'restaurant_id' => $restaurant->id,
            'delivery_type' => $type,
            'address_id' => $type === 'delivery' ? $address->id : null,
            'items' => [['product_id' => $product->id, 'qty' => 2]],
            'note' => 'qo‘ng‘iroqsiz',
        ]);
    }

    public function test_placing_an_order_queues_the_notification(): void
    {
        $order = $this->order();

        Queue::assertPushed(NotifyRestaurantOfNewOrder::class, fn ($job) => $job->orderId === $order->id);
    }

    public function test_job_sends_message_with_customer_details_when_chat_id_set(): void
    {
        $order = $this->order(['notify_chat_id' => '555111222']);

        $bot = app(Nutgram::class);
        (new NotifyRestaurantOfNewOrder($order->id))->handle($bot);

        $bot->assertCalled('sendMessage');
        $bot->assertRaw(function ($request) {
            $body = (string) $request->getBody();

            return str_contains($body, 'Ali Valiyev')
                && str_contains($body, '+998901112233')
                && str_contains($body, '@ali_v')
                && str_contains($body, 'Bobur 12')
                && str_contains($body, '555111222');
        });
        $bot->assertCalled('sendLocation'); // yetkazish -> lokatsiya pin
    }

    public function test_job_is_noop_without_chat_id(): void
    {
        $order = $this->order(); // notify_chat_id = null

        $bot = app(Nutgram::class);
        (new NotifyRestaurantOfNewOrder($order->id))->handle($bot);

        $bot->assertCalled('sendMessage', 0);
    }

    public function test_pickup_order_notification_has_no_location(): void
    {
        $order = $this->order(['notify_chat_id' => '999'], type: 'pickup');

        $bot = app(Nutgram::class);
        (new NotifyRestaurantOfNewOrder($order->id))->handle($bot);

        $bot->assertCalled('sendMessage');
        $bot->assertCalled('sendLocation', 0);
    }
}
