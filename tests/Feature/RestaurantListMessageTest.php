<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\District;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use SergiX44\Nutgram\Nutgram;
use Tests\TestCase;

/**
 * /start (profil to'lgan) — restoranlar ro'yxati "Buyurtma berish" (asosiy menyu)
 * dan OLDIN inline tugmalar bilan chiqadi.
 */
class RestaurantListMessageTest extends TestCase
{
    use RefreshDatabase;

    private const TG_ID = 555200;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('telegram.mini_app_url', 'https://mini.example/app');
        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00', 'Asia/Tashkent'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function bot(): Nutgram
    {
        /** @var Nutgram $bot */
        $bot = app(Nutgram::class);
        $bot->willStartConversation();

        return $bot;
    }

    private function completedUser(): User
    {
        $user = User::factory()->create([
            'telegram_id' => self::TG_ID,
            'full_name' => 'Ali Valiyev',
            'profile_completed' => true,
        ]);
        Address::factory()->for($user)->default()->create(['lat' => 40.7825, 'lng' => 72.3500]);

        return $user;
    }

    private function restaurant(array $attrs = []): Restaurant
    {
        $always = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], [['00:00', '23:59']]);

        return Restaurant::factory()->for(District::factory())->create(array_replace([
            'lat' => 40.7825, 'lng' => 72.3500, 'delivery_radius_km' => 8,
            'is_open' => true, 'work_hours' => $always,
        ], $attrs));
    }

    public function test_list_is_sent_before_welcome_back(): void
    {
        $this->completedUser();
        $this->restaurant(['name' => 'Milliy']);

        $bot = $this->bot();
        $bot->hearMessage([
            'from' => ['id' => self::TG_ID, 'first_name' => 'Ali', 'language_code' => 'uz'],
            'text' => '/start',
        ])->reply();

        $bot->assertReplyText(__('messages.restaurants.pick'), 0);
        $bot->assertReplyText(__('messages.welcome_back', ['name' => 'Ali Valiyev']), 1);
    }

    public function test_no_list_without_mini_app_url(): void
    {
        config()->set('telegram.mini_app_url', null);
        $this->completedUser();
        $this->restaurant(['name' => 'Milliy']);

        $bot = $this->bot();
        $bot->hearMessage([
            'from' => ['id' => self::TG_ID, 'first_name' => 'Ali', 'language_code' => 'uz'],
            'text' => '/start',
        ])->reply();

        $bot->assertReplyText(__('messages.welcome_back', ['name' => 'Ali Valiyev']), 0);
    }
}
