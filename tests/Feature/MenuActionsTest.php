<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\UpdateType;
use Tests\TestCase;

/**
 * Yangi asosiy menyu oqimi: buyurtma / restoranlar / manzillar / til /
 * taklif-shikoyat + "📍 Yangi manzil" (lokatsiya).
 */
class MenuActionsTest extends TestCase
{
    use RefreshDatabase;

    private const TG_ID = 555300;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('telegram.mini_app_url', 'https://mini.example/app');
        Http::fake(); // "📍 Yangi manzil" geokodlash — tashqi so'rovsiz
    }

    private function bot(): Nutgram
    {
        /** @var Nutgram $bot */
        $bot = app(Nutgram::class);
        $bot->willStartConversation();

        return $bot;
    }

    private function registered(): User
    {
        $user = User::factory()->create([
            'telegram_id' => self::TG_ID,
            'full_name' => 'Ali Valiyev',
            'language' => 'uz',
            'profile_completed' => true,
        ]);
        Address::factory()->for($user)->default()->create([
            'label' => 'Uy', 'lat' => 40.7825, 'lng' => 72.35, 'address_text' => 'Uy manzili',
        ]);

        return $user;
    }

    private function tap(Nutgram $bot, string $text): void
    {
        $bot->hearMessage([
            'from' => ['id' => self::TG_ID, 'first_name' => 'Ali', 'language_code' => 'uz'],
            'text' => $text,
        ])->reply();
    }

    public function test_order_button_opens_mini_app(): void
    {
        $this->registered();
        $bot = $this->bot();

        $this->tap($bot, __('messages.main_menu.order'));

        $bot->assertReplyText(__('messages.main_menu.order_intro'));
        $bot->assertRaw(function ($request) {
            $body = (string) $request->getBody();

            return str_contains($body, 'mini.example') && str_contains($body, 'web_app');
        });
    }

    public function test_restaurants_button_opens_list_screen(): void
    {
        $this->registered();
        $bot = $this->bot();

        $this->tap($bot, __('messages.main_menu.restaurants'));

        $bot->assertReplyText(__('messages.main_menu.restaurants_intro'));
        $bot->assertRaw(fn ($request) => str_contains((string) $request->getBody(), 'screen=restaurants'));
    }

    public function test_feedback_button_says_not_ready(): void
    {
        $this->registered();
        $bot = $this->bot();

        $this->tap($bot, __('messages.main_menu.feedback'));

        $bot->assertReplyText(__('messages.feedback.not_ready'));
    }

    public function test_addresses_button_lists_saved_addresses(): void
    {
        $this->registered();
        $bot = $this->bot();

        $this->tap($bot, __('messages.main_menu.addresses'));

        $bot->assertReplyMessage([
            'text' => __('messages.addresses.title')."\n\n"
                .'• Uy — Uy manzili'.__('messages.addresses.default_marker')."\n\n"
                .__('messages.addresses.hint'),
        ]);
    }

    public function test_new_address_location_is_saved_as_default(): void
    {
        $user = $this->registered();
        $bot = $this->bot();

        $bot->hearMessage([
            'from' => ['id' => self::TG_ID, 'first_name' => 'Ali'],
            'location' => ['latitude' => 40.79, 'longitude' => 72.34],
        ])->reply();

        $this->assertSame(2, $user->addresses()->count());
        $new = $user->addresses()->where('is_default', true)->sole();
        $this->assertEqualsWithDelta(40.79, $new->lat, 0.0001);
        $this->assertSame(__('messages.addresses.label', ['n' => 2]), $new->label);
        $bot->assertReplyText(__('messages.addresses.added', [
            'address' => $new->label.' — '.$new->address_text,
        ]));
    }

    public function test_settings_language_switch_updates_user(): void
    {
        $user = $this->registered();
        $bot = $this->bot();

        $this->tap($bot, __('messages.main_menu.settings'));
        $bot->assertReplyText(__('messages.settings.choose_language'));

        $bot->hearUpdateType(UpdateType::CALLBACK_QUERY, [
            'from' => ['id' => self::TG_ID, 'first_name' => 'Ali'],
            'data' => 'lang:ru',
            'message' => [
                'message_id' => 10,
                'date' => 1703892479,
                'chat' => ['id' => self::TG_ID, 'type' => 'private'],
            ],
        ])->reply();

        $this->assertSame('ru', $user->refresh()->language);
        $bot->assertCalled('answerCallbackQuery');
    }

    public function test_unregistered_user_is_sent_to_registration(): void
    {
        $bot = $this->bot();

        $this->tap($bot, __('messages.main_menu.order'));

        $bot->assertReplyText(__('messages.welcome'), 0);
        $bot->assertActiveConversation();
    }

    public function test_id_command_returns_chat_id(): void
    {
        $bot = $this->bot();
        $bot->hearMessage([
            'from' => ['id' => 424299, 'first_name' => 'Egasi'],
            'chat' => ['id' => 424299, 'type' => 'private'],
            'text' => '/id',
        ])->reply();

        $bot->assertRaw(fn ($request) => str_contains((string) $request->getBody(), '424299'));
    }
}
