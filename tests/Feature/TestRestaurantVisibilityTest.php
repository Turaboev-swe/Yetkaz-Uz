<?php

namespace Tests\Feature;

use App\Enums\BannerTarget;
use App\Events\OrderPlaced;
use App\Models\Address;
use App\Models\Banner;
use App\Models\Category;
use App\Models\District;
use App\Models\Order;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use Tests\Concerns\InteractsWithTelegramInitData;
use Tests\TestCase;

/**
 * Test restoran (restaurants.is_test): faqat TEST_TELEGRAM_IDS dagi hisoblar
 * ko'radi va buyurtma bera oladi. Boshqalarga u "mavjud emas" — ro'yxat, menyu,
 * qidiruv, banner, /start r_{id} havolasi va buyurtma yaratishda ham.
 * Ro'yxat bo'sh bo'lsa — hech kim ko'rmaydi. Oddiy restoranlar o'zgarmaydi.
 */
class TestRestaurantVisibilityTest extends TestCase
{
    use InteractsWithTelegramInitData;
    use RefreshDatabase;

    private const TESTER_TG = 1746546661;

    private const STRANGER_TG = 900901;

    private User $tester;

    private User $stranger;

    private Restaurant $real;

    private Restaurant $test;

    private Product $realBurger;

    private Product $testBurger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindInitDataValidator();
        Http::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-07 12:00', 'Asia/Tashkent'));
        config()->set('telegram.test_telegram_ids', [(string) self::TESTER_TG]);
        config()->set('telegram.mini_app_url', 'https://mini.example/app');

        $this->tester = User::factory()->create(['telegram_id' => self::TESTER_TG, 'phone' => '+998901110000']);
        $this->stranger = User::factory()->create(['telegram_id' => self::STRANGER_TG, 'phone' => '+998902220000']);

        $always = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], [['00:00', '23:59']]);
        $district = District::factory()->create();
        $attrs = [
            'lat' => 40.7833, 'lng' => 72.3506, 'is_open' => true, 'work_hours' => $always,
            'delivery_radius_km' => 8, 'delivery_fee' => 0, 'min_order_amount' => 0, 'avg_prep_time_min' => 20,
        ];

        $this->real = Restaurant::factory()->for($district)->create(['name' => 'Haqiqiy Burger', ...$attrs]);
        $this->test = Restaurant::factory()->for($district)->create(['name' => '🧪 Yetkaz Test', 'is_test' => true, ...$attrs]);

        $this->realBurger = Product::factory()
            ->for(Category::factory()->for($this->real)->create(['is_active' => true]))
            ->create(['name' => 'Burger klassik', 'price' => 3_000_000, 'is_available' => true]);
        $this->testBurger = Product::factory()
            ->for(Category::factory()->for($this->test)->create(['is_active' => true]))
            ->create(['name' => 'Burger test', 'price' => 3_000_000, 'is_available' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function headers(User $user): array
    {
        return $this->initDataHeaders($this->signedInitData(['id' => $user->telegram_id]));
    }

    private function address(User $user): Address
    {
        return $user->addresses()->first()
            ?? Address::factory()->for($user)->default()->create(['lat' => 40.7830, 'lng' => 72.3500]);
    }

    /** @return array<int, int> */
    private function listedIds(User $user): array
    {
        $address = $this->address($user);

        return collect($this->getJson("/api/restaurants?address_id={$address->id}&include_closed=1", $this->headers($user))
            ->assertOk()->json('data'))->pluck('id')->all();
    }

    private function orderPayload(Restaurant $restaurant, Product $product, Address $address): array
    {
        return [
            'restaurant_id' => $restaurant->id,
            'delivery_type' => 'delivery',
            'address_id' => $address->id,
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'qty' => 1]],
        ];
    }

    // --- Begona foydalanuvchi -----------------------------------------------

    public function test_stranger_does_not_see_test_restaurant_in_the_list(): void
    {
        $ids = $this->listedIds($this->stranger);

        $this->assertContains($this->real->id, $ids);
        $this->assertNotContains($this->test->id, $ids);
    }

    public function test_stranger_gets_404_for_test_restaurant_page_and_menu(): void
    {
        $this->getJson("/api/restaurants/{$this->test->id}", $this->headers($this->stranger))->assertNotFound();
        $this->getJson("/api/restaurants/{$this->test->id}/menu", $this->headers($this->stranger))->assertNotFound();
    }

    public function test_stranger_cannot_place_an_order_in_test_restaurant(): void
    {
        $address = $this->address($this->stranger);

        $this->postJson('/api/orders', $this->orderPayload($this->test, $this->testBurger, $address), $this->headers($this->stranger))
            ->assertNotFound();

        $this->assertSame(0, Order::query()->count());
    }

    public function test_stranger_cannot_estimate_or_check_promo_for_test_restaurant(): void
    {
        $address = $this->address($this->stranger);

        $this->postJson('/api/orders/estimate', [
            'restaurant_id' => $this->test->id, 'delivery_type' => 'delivery', 'address_id' => $address->id,
        ], $this->headers($this->stranger))->assertNotFound();

        $this->postJson('/api/promo-codes/validate', [
            'promo_code' => 'ANY', 'restaurant_id' => $this->test->id, 'subtotal' => 3_000_000,
        ], $this->headers($this->stranger))->assertNotFound();
    }

    public function test_stranger_search_does_not_return_test_restaurant_dishes(): void
    {
        $address = $this->address($this->stranger);

        $names = collect($this->getJson("/api/search?q=burger&address_id={$address->id}", $this->headers($this->stranger))
            ->assertOk()->json('data'))->pluck('product.name')->all();

        $this->assertContains('Burger klassik', $names);
        $this->assertNotContains('Burger test', $names);
    }

    public function test_stranger_does_not_see_test_restaurant_banner(): void
    {
        $realBanner = Banner::factory()->create(['target_type' => BannerTarget::Restaurant, 'restaurant_id' => $this->real->id]);
        $testBanner = Banner::factory()->create(['target_type' => BannerTarget::Restaurant, 'restaurant_id' => $this->test->id]);

        $ids = collect($this->getJson('/api/banners', $this->headers($this->stranger))->assertOk()->json('data'))->pluck('id')->all();

        $this->assertContains($realBanner->id, $ids);
        $this->assertNotContains($testBanner->id, $ids);
    }

    public function test_stranger_deeplink_to_test_restaurant_reveals_nothing(): void
    {
        $this->stranger->update(['profile_completed' => true, 'full_name' => 'Begona']);
        $bot = $this->bot();

        $this->start($bot, self::STRANGER_TG, "/start r_{$this->test->id}");

        $bot->assertReplyText(__('messages.main_menu.order_intro'));
        $bot->assertRaw(fn ($request) => ! str_contains((string) $request->getBody(), "r={$this->test->id}")
            && ! str_contains((string) $request->getBody(), 'Yetkaz Test'));
    }

    // --- Tester -------------------------------------------------------------

    public function test_tester_sees_test_restaurant_everywhere(): void
    {
        $this->assertContains($this->test->id, $this->listedIds($this->tester));

        $this->getJson("/api/restaurants/{$this->test->id}", $this->headers($this->tester))->assertOk();
        $this->getJson("/api/restaurants/{$this->test->id}/menu", $this->headers($this->tester))
            ->assertOk()->assertJsonPath('data.0.products.0.name', 'Burger test');

        $address = $this->address($this->tester);
        $names = collect($this->getJson("/api/search?q=burger&address_id={$address->id}", $this->headers($this->tester))
            ->json('data'))->pluck('product.name')->all();
        $this->assertContains('Burger test', $names);

        $banner = Banner::factory()->create(['target_type' => BannerTarget::Restaurant, 'restaurant_id' => $this->test->id]);
        $this->assertContains($banner->id, collect($this->getJson('/api/banners', $this->headers($this->tester))->json('data'))->pluck('id')->all());
    }

    public function test_tester_places_an_order_and_kitchen_flow_runs_as_usual(): void
    {
        Event::fake([OrderPlaced::class]);
        $address = $this->address($this->tester);

        $this->postJson('/api/orders', $this->orderPayload($this->test, $this->testBurger, $address), $this->headers($this->tester))
            ->assertCreated();

        $order = Order::query()->sole();
        $this->assertSame($this->test->id, $order->restaurant_id);
        // Oshxona paneli (Reverb) hodisasi test restoranda ham oddiy chiqadi.
        Event::assertDispatched(OrderPlaced::class);
    }

    public function test_tester_deeplink_opens_test_restaurant(): void
    {
        $this->tester->update(['profile_completed' => true, 'full_name' => 'Tester']);
        $bot = $this->bot();

        $this->start($bot, self::TESTER_TG, "/start r_{$this->test->id}");

        $bot->assertRaw(fn ($request) => str_contains((string) $request->getBody(), "r={$this->test->id}"));
    }

    // --- Bo'sh ro'yxat — xavfsiz standart -------------------------------------

    public function test_empty_list_hides_test_restaurant_from_everyone(): void
    {
        config()->set('telegram.test_telegram_ids', []);

        $this->assertNotContains($this->test->id, $this->listedIds($this->tester));
        $this->getJson("/api/restaurants/{$this->test->id}/menu", $this->headers($this->tester))->assertNotFound();

        $address = $this->address($this->tester);
        $this->postJson('/api/orders', $this->orderPayload($this->test, $this->testBurger, $address), $this->headers($this->tester))
            ->assertNotFound();
    }

    // --- Oddiy restoranlar o'zgarmaydi ----------------------------------------

    public function test_regular_restaurant_is_unaffected_for_everyone(): void
    {
        foreach ([$this->tester, $this->stranger] as $user) {
            $this->assertContains($this->real->id, $this->listedIds($user));
            $this->getJson("/api/restaurants/{$this->real->id}/menu", $this->headers($user))->assertOk();
        }

        $address = $this->address($this->stranger);
        $this->postJson('/api/orders', $this->orderPayload($this->real, $this->realBurger, $address), $this->headers($this->stranger))
            ->assertCreated();
    }

    public function test_is_test_defaults_to_false(): void
    {
        $this->assertFalse(Restaurant::factory()->create()->fresh()->is_test);
    }

    private function bot(): Nutgram
    {
        /** @var Nutgram $bot */
        $bot = app(Nutgram::class);
        $bot->willStartConversation();

        return $bot;
    }

    private function start(Nutgram $bot, int $telegramId, string $text): void
    {
        $bot->hearMessage([
            'from' => ['id' => $telegramId, 'first_name' => 'Ali', 'language_code' => 'uz'],
            'text' => $text,
        ])->reply();
    }
}
