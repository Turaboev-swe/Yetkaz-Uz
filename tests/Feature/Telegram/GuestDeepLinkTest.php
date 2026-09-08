<?php

namespace Tests\Feature\Telegram;

use App\Models\District;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use Tests\TestCase;

/**
 * QADAM A + B: QR / chuqur havola (`/start r_{id}`, `/start phone[_r_{id}]`) va
 * mehmon telefon oqimi (GuestPhoneConversation).
 */
class GuestDeepLinkTest extends TestCase
{
    use RefreshDatabase;

    private const TG_ID = 556001;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('telegram.mini_app_url', 'https://mini.example/app');
        config()->set('telegram.bot_username', 'RasmUstasiBot');
        Http::fake();

        $this->restaurant = Restaurant::factory()->for(District::factory())->create(['name' => 'Vanilla']);
    }

    private function bot(): Nutgram
    {
        /** @var Nutgram $bot */
        $bot = app(Nutgram::class);
        $bot->willStartConversation();

        return $bot;
    }

    private function start(Nutgram $bot, string $text, array $from = []): void
    {
        $bot->hearMessage([
            'from' => array_replace(['id' => self::TG_ID, 'first_name' => 'Ali', 'language_code' => 'uz'], $from),
            'text' => $text,
        ])->reply();
    }

    private function registered(): User
    {
        return User::factory()->create([
            'telegram_id' => self::TG_ID,
            'full_name' => 'Ali Valiyev',
            'phone' => '+998901112233',
            'profile_completed' => true,
        ]);
    }

    // --- QADAM A: restoran chuqur havolasi ------------------------------

    public function test_registered_user_deeplink_opens_restaurant_menu_directly(): void
    {
        $this->registered();
        $bot = $this->bot();

        $this->start($bot, "/start r_{$this->restaurant->id}");

        $bot->assertReplyText(__('messages.main_menu.order_intro'));
        $bot->assertRaw(fn ($request) => str_contains((string) $request->getBody(), "r={$this->restaurant->id}")
            && ! str_contains((string) $request->getBody(), 'guest'));
        $bot->assertNoConversation();
    }

    public function test_unregistered_user_deeplink_opens_guest_menu_without_registration(): void
    {
        $bot = $this->bot();

        $this->start($bot, "/start r_{$this->restaurant->id}");

        $bot->assertReplyText(__('messages.main_menu.order_intro'));
        $bot->assertRaw(fn ($request) => str_contains((string) $request->getBody(), "r={$this->restaurant->id}")
            && str_contains((string) $request->getBody(), 'guest'));
        // MUHIM: to'liq ro'yxatdan o'tish suhbati BOSHLANMAYDI.
        $bot->assertNoConversation();

        $this->assertDatabaseHas('users', ['telegram_id' => self::TG_ID, 'profile_completed' => false]);
    }

    public function test_plain_start_still_begins_full_registration_for_new_user(): void
    {
        $bot = $this->bot();

        $this->start($bot, '/start');

        $bot->assertReplyText(__('messages.welcome'), 0);
        $bot->assertActiveConversation();
    }

    public function test_plain_start_greets_a_registered_user(): void
    {
        $this->registered();
        $bot = $this->bot();

        $this->start($bot, '/start');

        $bot->assertReplyText(__('messages.welcome_back', ['name' => 'Ali Valiyev']));
        $bot->assertNoConversation();
    }

    // --- QADAM B: mehmon telefon oqimi --------------------------------

    public function test_phone_deeplink_asks_only_for_phone(): void
    {
        $bot = $this->bot();

        $this->start($bot, "/start phone_r_{$this->restaurant->id}");

        $bot->assertReplyText(__('messages.guest_phone.ask'), 1);
        $bot->assertActiveConversation();
    }

    public function test_guest_phone_flow_autofills_name_and_completes_without_address(): void
    {
        $bot = $this->bot();

        $this->start($bot, "/start phone_r_{$this->restaurant->id}", [
            'first_name' => 'Akmal', 'last_name' => 'Karimov',
        ]);

        $bot->hearMessage([
            'from' => ['id' => self::TG_ID, 'first_name' => 'Akmal', 'last_name' => 'Karimov'],
            'contact' => ['phone_number' => '998901234567', 'first_name' => 'Akmal', 'user_id' => self::TG_ID],
        ])->reply();

        $user = User::byTelegramId(self::TG_ID)->firstOrFail();
        $this->assertSame('+998901234567', $user->phone);
        $this->assertSame('Akmal Karimov', $user->full_name);   // Telegram profilidan — so'ralmagan
        $this->assertTrue($user->profile_completed);
        $this->assertSame(0, $user->addresses()->count());       // lokatsiya so'ralmagan

        $bot->assertReplyText(__('messages.guest_phone.done'), 0);
        $bot->assertRaw(fn ($request) => str_contains((string) $request->getBody(), "r={$this->restaurant->id}")
            && str_contains((string) $request->getBody(), 'web_app'), 1);
        $bot->assertNoConversation();
    }

    public function test_guest_phone_flow_rejects_a_typed_number(): void
    {
        $bot = $this->bot();

        $this->start($bot, '/start phone');
        $bot->hearMessage(['from' => ['id' => self::TG_ID, 'first_name' => 'Ali'], 'text' => '+998901234567'])->reply();

        $bot->assertReplyText(__('messages.registration.phone_must_use_button'));
        $this->assertNull(User::byTelegramId(self::TG_ID)->value('phone'));
        $bot->assertActiveConversation();
    }

    public function test_registered_user_phone_deeplink_is_not_reasked(): void
    {
        $this->registered();
        $bot = $this->bot();

        $this->start($bot, "/start phone_r_{$this->restaurant->id}");

        $bot->assertReplyText(__('messages.main_menu.order_intro'));
        $bot->assertRaw(fn ($request) => str_contains((string) $request->getBody(), "r={$this->restaurant->id}"));
        $bot->assertNoConversation();
    }
}
