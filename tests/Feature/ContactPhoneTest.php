<?php

namespace Tests\Feature;

use App\Http\Resources\KitchenOrderResource;
use App\Models\Address;
use App\Models\Category;
use App\Models\District;
use App\Models\Order;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use App\Telegram\Support\KitchenOrderMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithTelegramInitData;
use Tests\TestCase;

/** Aloqa raqami (contact_phone): buyurtma, foydalanuvchi standarti, oshxonada ko'rinishi. */
class ContactPhoneTest extends TestCase
{
    use InteractsWithTelegramInitData;
    use RefreshDatabase;

    private User $user;

    private Address $address;

    private Restaurant $restaurant;

    private Product $osh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindInitDataValidator();
        Http::fake();
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00', 'Asia/Tashkent'));

        $this->user = User::factory()->create(['telegram_id' => 900901, 'phone' => '+998901112233', 'contact_phone' => null, 'username' => 'secret_nick']);
        $this->address = Address::factory()->for($this->user)->default()->create(['lat' => 40.7830, 'lng' => 72.3500]);

        $always = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], [['00:00', '23:59']]);
        $this->restaurant = Restaurant::factory()->for(District::factory())->create([
            'lat' => 40.7833, 'lng' => 72.3506, 'is_open' => true, 'work_hours' => $always,
            'delivery_radius_km' => 8, 'delivery_fee' => 0, 'min_order_amount' => 0,
        ]);
        $cat = Category::factory()->for($this->restaurant)->create(['is_active' => true]);
        $this->osh = Product::factory()->for($cat)->create(['price' => 3_200_000, 'is_available' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function place(array $overrides = [])
    {
        $headers = $this->initDataHeaders($this->signedInitData(['id' => $this->user->telegram_id]));

        return $this->postJson('/api/orders', array_replace([
            'restaurant_id' => $this->restaurant->id,
            'delivery_type' => 'delivery',
            'address_id' => $this->address->id,
            'items' => [['product_id' => $this->osh->id, 'qty' => 1]],
        ], $overrides), $headers);
    }

    public function test_contact_phone_is_normalized_saved_and_becomes_the_user_default(): void
    {
        $this->place(['contact_phone' => '8 (93) 555-66-77'])->assertCreated();

        $order = Order::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame('+998935556677', $order->contact_phone);
        $this->assertSame('+998935556677', $this->user->fresh()->contact_phone);
        $this->assertSame('+998901112233', $this->user->fresh()->phone, 'Telegram raqami o‘zgarmaydi');
    }

    public function test_nine_digit_88_number_is_not_mangled(): void
    {
        $this->place(['contact_phone' => '881112233'])->assertCreated();

        $this->assertSame('+998881112233', Order::withoutGlobalScopes()->latest('id')->first()->contact_phone);
    }

    public function test_invalid_contact_phone_is_rejected_with_uzbek_message(): void
    {
        $this->place(['contact_phone' => '12345'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('contact_phone');

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    public function test_without_input_the_saved_contact_then_telegram_phone_is_used(): void
    {
        $this->place()->assertCreated();
        $this->assertSame('+998901112233', Order::withoutGlobalScopes()->latest('id')->first()->contact_phone);

        $this->user->update(['contact_phone' => '+998977778899']);
        $this->place()->assertCreated();
        $this->assertSame('+998977778899', Order::withoutGlobalScopes()->latest('id')->first()->contact_phone);
    }

    public function test_me_endpoint_exposes_contact_phone(): void
    {
        $this->user->update(['contact_phone' => '+998977778899']);
        $headers = $this->initDataHeaders($this->signedInitData(['id' => $this->user->telegram_id]));

        $this->getJson('/api/me', $headers)->assertOk()->assertJsonPath('data.contact_phone', '+998977778899');
    }

    public function test_kitchen_resource_shows_both_numbers_when_they_differ_and_never_the_username(): void
    {
        $this->place(['contact_phone' => '935556677'])->assertCreated();
        $order = Order::withoutGlobalScopes()->with(['user', 'restaurant'])->latest('id')->first();

        $customer = (new KitchenOrderResource($order))->toArray(Request::create('/'))['customer'];

        $this->assertSame('+998935556677', $customer['contact_phone']);
        $this->assertSame('+998901112233', $customer['phone']);
        $this->assertTrue($customer['phones_differ']);
        $this->assertArrayNotHasKey('username', $customer);
        $this->assertStringNotContainsString('secret_nick', json_encode((new KitchenOrderResource($order))->toArray(Request::create('/'))));
    }

    public function test_kitchen_resource_shows_one_number_when_same_or_legacy_order(): void
    {
        $same = Order::factory()->for($this->restaurant)->for($this->user)->create(['contact_phone' => '+998 90 111 22 33']);
        $legacy = Order::factory()->for($this->restaurant)->for($this->user)->create(['contact_phone' => null]);

        foreach ([$same, $legacy] as $order) {
            $customer = (new KitchenOrderResource($order->load(['user', 'restaurant'])))->toArray(Request::create('/'))['customer'];
            $this->assertFalse($customer['phones_differ']);
        }

        $this->assertSame('+998901112233', (new KitchenOrderResource($legacy))->toArray(Request::create('/'))['customer']['contact_phone']);
    }

    public function test_bot_message_to_restaurant_shows_both_numbers_only_when_different(): void
    {
        $order = Order::factory()->for($this->restaurant)->for($this->user)->create(['contact_phone' => '+998935556677']);
        $text = app(KitchenOrderMessage::class)->text($order->load(['user', 'restaurant']));

        $this->assertStringContainsString('📞 +998935556677', $text);
        $this->assertStringContainsString('📱 Telegram: +998901112233', $text);

        $order->update(['contact_phone' => '+998901112233']);
        $this->assertStringNotContainsString('Telegram:', app(KitchenOrderMessage::class)->text($order->fresh(['user', 'restaurant'])));
    }
}
