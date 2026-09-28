<?php

namespace Tests\Feature;

use App\Enums\CourierType;
use App\Enums\OrderStatus;
use App\Jobs\AlertAdminOfUnacceptedOrder;
use App\Jobs\NotifyCustomerOfStatusChange;
use App\Jobs\NotifyRestaurantOfNewOrder;
use App\Jobs\RepeatKitchenPush;
use App\Models\Address;
use App\Models\Category;
use App\Models\District;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Restaurant;
use App\Models\Staff;
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

    private function order(array $restaurantAttrs = [], string $type = 'delivery', ?string $promoCode = null): Order
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
            'promo_code' => $promoCode,
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

    public function test_owner_dm_is_skipped_when_that_chat_already_gets_the_kitchen_message(): void
    {
        $order = $this->order(['notify_chat_id' => '555111222']);
        Staff::factory()->owner($order->restaurant_id)->create(['telegram_chat_id' => 555111222]);

        $bot = app(Nutgram::class);
        (new NotifyRestaurantOfNewOrder($order->id))->handle($bot);

        $bot->assertCalled('sendMessage', 0);
        $bot->assertCalled('sendLocation', 0);
    }

    public function test_owner_dm_is_still_sent_when_the_chat_belongs_to_an_inactive_staff(): void
    {
        $order = $this->order(['notify_chat_id' => '555111222']);
        Staff::factory()->owner($order->restaurant_id)->create(['telegram_chat_id' => 555111222, 'is_active' => false]);

        $bot = app(Nutgram::class);
        (new NotifyRestaurantOfNewOrder($order->id))->handle($bot);

        $bot->assertCalled('sendMessage', 1);
    }

    public function test_placing_an_order_starts_push_repeats_and_the_admin_alert(): void
    {
        $order = $this->order();

        Queue::assertPushed(RepeatKitchenPush::class, fn ($job) => $job->orderId === $order->id
            && $job->dueAt === $order->created_at->copy()->addSeconds(60)->getTimestamp());
        Queue::assertPushed(AlertAdminOfUnacceptedOrder::class, fn ($job) => $job->orderId === $order->id
            && $job->dueAt === $order->created_at->copy()->addMinutes(7)->getTimestamp());
    }

    public function test_owner_dm_shows_the_discount_split_and_the_amount_to_collect(): void
    {
        PromoCode::factory()->percent(20)->restaurantShare(25)->create(['code' => 'OSON20']);
        $order = $this->order(['notify_chat_id' => '555111222'], promoCode: 'OSON20');

        $bot = app(Nutgram::class);
        (new NotifyRestaurantOfNewOrder($order->id))->handle($bot);

        // 64 000 so'm taom, 20% = 12 800 so'm chegirma: restoran 25% = 3 200 so'm,
        // platforma 9 600 so'm. Mijozdan: 64 000 + 10 000 - 12 800 = 61 200 so'm.
        $body = $this->sentBody($bot);
        $this->assertStringContainsString('Chegirma', $body);
        $this->assertStringContainsString('12 800', $body);
        $this->assertStringContainsString('restoran: 3 200', $body);
        $this->assertStringContainsString('platforma qoplaydi: 9 600', $body);
        $this->assertStringContainsString('Mijozdan olinadi: 61 200', $body);
    }

    public function test_owner_dm_shows_the_amount_to_collect_without_a_discount_line_when_no_code(): void
    {
        $order = $this->order(['notify_chat_id' => '555111222']);

        $bot = app(Nutgram::class);
        (new NotifyRestaurantOfNewOrder($order->id))->handle($bot);

        $body = $this->sentBody($bot);
        $this->assertStringNotContainsString('Chegirma', $body);
        $this->assertStringContainsString('Mijozdan olinadi: 74 000', $body); // 64 000 + 10 000
    }

    /** Birinchi sendMessage so'rovining matni (JSON'dan — unicode ochilgan holda). */
    private function sentBody(Nutgram $bot): string
    {
        foreach ($bot->getRequestHistory() as $entry) {
            if (str_ends_with((string) $entry['request']->getUri(), 'sendMessage')) {
                return (string) (json_decode((string) $entry['request']->getBody(), true)['text'] ?? '');
            }
        }

        $this->fail('sendMessage yuborilmadi');
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

    public function test_customer_status_message_uses_customer_language(): void
    {
        $order = $this->order();
        $order->user->update(['language' => 'ru', 'telegram_id' => 700123]);
        $order->update(['status' => OrderStatus::Preparing]);

        $bot = app(Nutgram::class);
        (new NotifyCustomerOfStatusChange($order->id))->handle($bot);

        $bot->assertRaw(fn ($request) => str_contains((string) $request->getBody(), 'готовится'), 0);

        // uz mijoz -> o'zbekcha
        $order->user->update(['language' => 'uz']);
        $order->update(['status' => OrderStatus::OnTheWay]);
        (new NotifyCustomerOfStatusChange($order->id))->handle($bot);
        $bot->assertRaw(fn ($request) => str_contains((string) $request->getBody(), 'lga chiqdi'), 1);
    }

    public function test_on_the_way_message_for_own_staff_courier_says_kuryer(): void
    {
        $order = $this->order();
        $order->user->update(['telegram_id' => 700200, 'language' => 'uz']);
        $order->update([
            'status' => OrderStatus::OnTheWay,
            'courier_type' => CourierType::OwnStaff,
            'courier_name' => 'Alisher',
            'courier_phone' => '+998901112233',
        ]);

        $bot = app(Nutgram::class);
        (new NotifyCustomerOfStatusChange($order->id))->handle($bot);

        $bot->assertRaw(fn ($r) => str_contains((string) $r->getBody(), 'Kuryer: Alisher')
            && str_contains((string) $r->getBody(), '+998901112233')
            && ! str_contains((string) $r->getBody(), 'Royal Taxi'));
    }

    public function test_on_the_way_message_for_taxi_courier_says_royal_taxi_not_kuryer(): void
    {
        $order = $this->order();
        $order->user->update(['telegram_id' => 700201, 'language' => 'uz']);
        $order->update([
            'status' => OrderStatus::OnTheWay,
            'courier_type' => CourierType::Taxi,
            'courier_name' => 'Royal Taxi',
            'courier_phone' => '+998901112233',
        ]);

        $bot = app(Nutgram::class);
        (new NotifyCustomerOfStatusChange($order->id))->handle($bot);

        $bot->assertRaw(fn ($r) => str_contains((string) $r->getBody(), 'Royal Taxi orqali')
            && str_contains((string) $r->getBody(), 'Royal Taxi: +998901112233')
            && ! str_contains((string) $r->getBody(), 'Kuryer:'));
    }
}
