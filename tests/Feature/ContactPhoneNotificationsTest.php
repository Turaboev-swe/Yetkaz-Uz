<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Jobs\AlertAdminOfUnacceptedOrder;
use App\Jobs\NotifyRestaurantOfNewOrder;
use App\Models\District;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use Tests\TestCase;

/**
 * Egaga "🔔 Yangi buyurtma" va platforma adminiga "qabul qilinmagan buyurtma" xabarlarida
 * asosiy raqam — aloqa raqami (orders.contact_phone, bo'lmasa users.phone); farq qilsa
 * Telegram raqami ham. Egaga mijozning @username'i berilmaydi.
 */
class ContactPhoneNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        $this->restaurant = Restaurant::factory()->for(District::factory())->create(['notify_chat_id' => 555123, 'name' => 'Osh Markazi']);
        $this->customer = User::factory()->create([
            'full_name' => 'Ali Valiyev', 'phone' => '+998901112233', 'username' => 'ali_secret',
        ]);
        Staff::factory()->platformAdmin()->withTelegramChatId(777001)->create();
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(?string $contactPhone): Order
    {
        return Order::factory()->for($this->restaurant)->for($this->customer)->create([
            'status' => OrderStatus::New,
            'contact_phone' => $contactPhone,
            'address_snapshot' => ['address_text' => 'Navoi 1', 'lat' => 40.78, 'lng' => 72.35],
        ]);
    }

    private function sentText(Nutgram $bot): string
    {
        return (string) json_decode((string) $bot->getRequestHistory()[0]['request']->getBody(), true)['text'];
    }

    private function ownerMessage(Order $order): string
    {
        $this->app->forgetInstance(Nutgram::class);
        $bot = app(Nutgram::class);
        (new NotifyRestaurantOfNewOrder($order->id))->handle($bot);

        return $this->sentText($bot);
    }

    private function adminMessage(Order $order): string
    {
        $placed = now()->startOfSecond();
        $order->update(['created_at' => $placed]);
        \Illuminate\Support\Carbon::setTestNow($placed->copy()->addMinutes(8));

        $this->app->forgetInstance(Nutgram::class);
        $bot = app(Nutgram::class);
        (new AlertAdminOfUnacceptedOrder($order->id, $placed->copy()->addMinutes(7)->getTimestamp()))->handle($bot);

        return $this->sentText($bot);
    }

    // --- Egaga "Yangi buyurtma" ----------------------------------------------------------

    public function test_owner_message_shows_contact_phone_and_telegram_phone_when_they_differ(): void
    {
        $text = $this->ownerMessage($this->order('+998935556677'));

        $this->assertStringContainsString('📞 +998935556677', $text);
        $this->assertStringContainsString('📱 Telegram: +998901112233', $text);
        $this->assertStringNotContainsString('📞 +998901112233', $text, 'asosiy raqam Telegram raqami emas');
    }

    public function test_owner_message_shows_a_single_number_when_contact_equals_telegram(): void
    {
        $text = $this->ownerMessage($this->order('+998 90 111 22 33'));

        $this->assertStringContainsString('📞', $text);
        $this->assertStringNotContainsString('Telegram:', $text);
    }

    public function test_owner_message_falls_back_to_telegram_phone_for_orders_without_contact_phone(): void
    {
        $text = $this->ownerMessage($this->order(null));

        $this->assertStringContainsString('📞 +998901112233', $text);
        $this->assertStringNotContainsString('Telegram:', $text);
    }

    public function test_owner_message_never_contains_the_customers_username(): void
    {
        foreach ([null, '+998935556677'] as $contact) {
            $text = $this->ownerMessage($this->order($contact));

            $this->assertStringNotContainsString('ali_secret', $text);
            $this->assertStringNotContainsString('@', $text);
            $this->assertStringNotContainsString('username', $text);
            $this->assertStringContainsString('Ali Valiyev', $text); // ism avvalgidek
        }
    }

    // --- Platforma adminiga "qabul qilinmagan buyurtma" ------------------------------------

    public function test_admin_alert_shows_contact_phone_first_and_telegram_phone_when_different(): void
    {
        $text = $this->adminMessage($this->order('+998935556677'));

        $this->assertStringContainsString('Mijoz: +998935556677', $text);
        $this->assertStringContainsString('📱 Telegram: +998901112233', $text);
    }

    public function test_admin_alert_shows_one_number_when_same_or_missing_contact_phone(): void
    {
        $same = $this->adminMessage($this->order('+998901112233'));
        $this->assertStringContainsString('Mijoz: +998901112233', $same);
        $this->assertStringNotContainsString('Telegram:', $same);

        $legacy = $this->adminMessage($this->order(null));
        $this->assertStringContainsString('Mijoz: +998901112233', $legacy);
        $this->assertStringNotContainsString('Telegram:', $legacy);
    }
}
