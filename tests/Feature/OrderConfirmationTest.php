<?php

namespace Tests\Feature;

use App\Jobs\SendOrderConfirmationToCustomer;
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

class OrderConfirmationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00', 'Asia/Tashkent'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $restaurantAttrs
     */
    private function order(array $restaurantAttrs = [], string $type = 'delivery', string $language = 'uz', ?string $note = null): Order
    {
        $always = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], [['08:00', '23:00']]);

        $restaurant = Restaurant::factory()->for(District::factory(['name' => 'Chilonzor']))->create(array_replace([
            'name' => 'Donix', 'lat' => 40.78, 'lng' => 72.35, 'phone' => '+998901234567',
            'is_open' => true, 'work_hours' => $always,
            'delivery_radius_km' => 8, 'delivery_fee' => 1_000_000, 'min_order_amount' => 1,
        ], $restaurantAttrs));

        $cat = Category::factory()->for($restaurant)->create(['is_active' => true]);
        $burger = Product::factory()->for($cat)->create(['name' => 'Lavash oddiy', 'price' => 3_000_000, 'is_available' => true]);
        $cola = Product::factory()->for($cat)->create(['name' => 'Coca-Cola', 'price' => 800_000, 'is_available' => true]);

        $user = User::factory()->create([
            'full_name' => 'Ali Valiyev', 'phone' => '+998901112233', 'language' => $language, 'telegram_id' => 700_100,
        ]);
        $address = Address::factory()->for($user)->default()->create([
            'lat' => 40.781, 'lng' => 72.351, 'address_text' => 'Amir Temur ko‘chasi, 12-uy',
        ]);

        return app(OrderService::class)->place($user, [
            'restaurant_id' => $restaurant->id,
            'delivery_type' => $type,
            'address_id' => $type === 'delivery' ? $address->id : null,
            'items' => [
                ['product_id' => $burger->id, 'qty' => 2],
                ['product_id' => $cola->id, 'qty' => 1],
            ],
            'note' => $note,
        ]);
    }

    private function send(Order $order): string
    {
        $bot = app(Nutgram::class);
        (new SendOrderConfirmationToCustomer($order->id))->handle($bot);

        $bot->assertCalled('sendMessage');

        $body = '';
        $bot->assertRaw(function ($request) use (&$body) {
            $body = (string) $request->getBody();

            return true;
        });

        return $body;
    }

    public function test_placing_an_order_queues_the_confirmation(): void
    {
        $order = $this->order();

        Queue::assertPushed(SendOrderConfirmationToCustomer::class, fn ($job) => $job->orderId === $order->id);
    }

    public function test_delivery_receipt_has_items_totals_address_and_eta(): void
    {
        $order = $this->order();
        $body = $this->send($order);

        // Sarlavha + restoran
        $this->assertStringContainsString('Buyurtma #'.$order->order_number, $body);
        $this->assertStringContainsString('Donix', $body);

        // Qatorlar — narx tiyindan so'mga, minglik ajratkich bilan
        $this->assertStringContainsString('2x Lavash oddiy', $body);
        $this->assertStringContainsString('60 000', $body);   // 2 × 30 000
        $this->assertStringContainsString('1x Coca-Cola', $body);
        $this->assertStringContainsString('8 000', $body);

        // Yakuniy summalar
        $this->assertStringContainsString('Taomlar', $body);
        $this->assertStringContainsString('68 000', $body);   // subtotal
        $this->assertStringContainsString('Yetkazish', $body);
        $this->assertStringContainsString('10 000', $body);   // delivery_fee
        $this->assertStringContainsString('Jami', $body);
        $this->assertStringContainsString('78 000', $body);   // total
        $this->assertStringContainsString('so\'m', $body);

        // Manzil, to'lov, ETA
        $this->assertStringContainsString('Amir Temur ko‘chasi, 12-uy', $body);
        $this->assertStringContainsString('Naqd', $body);
        $this->assertStringContainsString('daqiqa', $body);
        $this->assertStringContainsString('qabul qilindi', $body);

        // Olib ketish emas — restoran ish vaqti chiqmaydi
        $this->assertStringNotContainsString('Ish vaqti', $body);
    }

    public function test_pickup_receipt_shows_restaurant_hours_and_omits_delivery_fee(): void
    {
        $order = $this->order(type: 'pickup');
        $body = $this->send($order);

        $this->assertStringContainsString('Olib ketish', $body);
        $this->assertStringContainsString('Chilonzor', $body);
        $this->assertStringContainsString('+998901234567', $body);
        $this->assertStringContainsString('Ish vaqti', $body);
        $this->assertStringContainsString('08:00', $body);
        $this->assertStringContainsString('23:00', $body);

        // Yetkazish qatori umuman yo'q
        $this->assertStringNotContainsString('Yetkazish', $body);
        // Manzil qatori ham yo'q (yetkazish manzili ko'rsatilmaydi)
        $this->assertStringNotContainsString('Amir Temur', $body);

        // Jami = faqat taomlar (delivery_fee = 0)
        $this->assertStringContainsString('68 000', $body);
    }

    public function test_receipt_is_sent_in_customer_language(): void
    {
        $order = $this->order(language: 'ru');
        $body = $this->send($order);

        $this->assertStringContainsString('Заказ #'.$order->order_number, $body);
        $this->assertStringContainsString('Итого', $body);
        $this->assertStringContainsString('Оплата', $body);
        $this->assertStringContainsString('Наличные', $body);
        $this->assertStringContainsString('минут', $body);
        $this->assertStringContainsString('Ваш заказ принят', $body);

        $this->assertStringNotContainsString('Jami', $body);
    }

    public function test_customer_note_is_included_when_present(): void
    {
        $order = $this->order(note: 'qo‘ng‘iroqsiz, eshik oldiga qo‘ying');
        $body = $this->send($order);

        $this->assertStringContainsString('Izoh', $body);
        $this->assertStringContainsString('qo‘ng‘iroqsiz, eshik oldiga qo‘ying', $body);
    }

    public function test_job_is_noop_for_missing_order(): void
    {
        $bot = app(Nutgram::class);
        (new SendOrderConfirmationToCustomer(999_999))->handle($bot);

        $bot->assertCalled('sendMessage', 0);
    }
}
