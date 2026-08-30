<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SergiX44\Nutgram\Nutgram;
use Tests\TestCase;

/**
 * 2-bosqich: /start dan boshlanadigan ro'yxatdan o'tish oqimi
 * (telefon -> ism -> lokatsiya), Nutgram fake bilan.
 */
class RegistrationFlowTest extends TestCase
{
    use RefreshDatabase;

    private const TG_ID = 555001;

    private function bot(): Nutgram
    {
        /** @var Nutgram $bot */
        $bot = app(Nutgram::class);
        $bot->willStartConversation();

        return $bot;
    }

    private function start(Nutgram $bot): void
    {
        $bot->hearMessage([
            'from' => ['id' => self::TG_ID, 'first_name' => 'Ali', 'language_code' => 'uz'],
            'text' => '/start',
        ])->reply();
    }

    public function test_start_creates_user_and_asks_for_phone(): void
    {
        $bot = $this->bot();
        $this->start($bot);

        $this->assertDatabaseHas('users', [
            'telegram_id' => self::TG_ID,
            'profile_completed' => false,
        ]);
        $bot->assertReplyText(__('messages.registration.ask_phone'), 1);
        $bot->assertActiveConversation();
    }

    public function test_full_flow_phone_then_name_then_location(): void
    {
        $bot = $this->bot();

        $this->start($bot);

        // 1. telefon (request_contact)
        $bot->hearMessage([
            'contact' => ['phone_number' => '998901234567', 'first_name' => 'Ali', 'user_id' => self::TG_ID],
        ])->reply();
        $bot->assertReplyText(__('messages.registration.ask_name'));
        $this->assertSame('+998901234567', User::byTelegramId(self::TG_ID)->value('phone'));

        // 2. ism
        $bot->hearMessage(['text' => 'Ali Valiyev'])->reply();
        $bot->assertReplyText(__('messages.registration.ask_location'));
        $this->assertSame('Ali Valiyev', User::byTelegramId(self::TG_ID)->value('full_name'));

        // 3. lokatsiya
        $bot->hearMessage(['location' => ['latitude' => 41.311, 'longitude' => 69.279]])->reply();

        $user = User::byTelegramId(self::TG_ID)->firstOrFail();
        $this->assertTrue($user->profile_completed);

        $address = $user->addresses()->firstOrFail();
        $this->assertSame(Address::LABEL_HOME, $address->label);
        $this->assertTrue($address->is_default);
        $this->assertEqualsWithDelta(41.311, $address->lat, 0.0001);
        $this->assertEqualsWithDelta(69.279, $address->lng, 0.0001);

        $bot->assertNoConversation();
    }

    public function test_phone_must_come_from_the_contact_button_not_typed(): void
    {
        $bot = $this->bot();
        $this->start($bot);

        $bot->hearMessage(['text' => '+998901234567'])->reply();

        $bot->assertReplyText(__('messages.registration.phone_must_use_button'));
        $this->assertNull(User::byTelegramId(self::TG_ID)->value('phone'));
        $bot->assertActiveConversation();
    }

    public function test_rejects_someone_elses_shared_contact(): void
    {
        $bot = $this->bot();
        $this->start($bot);

        $bot->hearMessage([
            'contact' => ['phone_number' => '998900000000', 'first_name' => 'Boshqa', 'user_id' => 999999],
        ])->reply();

        $bot->assertReplyText(__('messages.registration.phone_must_be_own'));
        $this->assertNull(User::byTelegramId(self::TG_ID)->value('phone'));
    }

    public function test_short_name_is_rejected(): void
    {
        $bot = $this->bot();
        $this->start($bot);
        $bot->hearMessage([
            'contact' => ['phone_number' => '998901234567', 'first_name' => 'Ali', 'user_id' => self::TG_ID],
        ])->reply();

        $bot->hearMessage(['text' => 'A'])->reply();

        $bot->assertReplyText(__('messages.registration.name_too_short'));
        $this->assertNull(User::byTelegramId(self::TG_ID)->value('full_name'));
    }

    public function test_completed_user_gets_menu_and_is_not_asked_again(): void
    {
        User::factory()->create([
            'telegram_id' => self::TG_ID,
            'full_name' => 'Ali Valiyev',
            'profile_completed' => true,
        ]);

        $bot = $this->bot();
        $this->start($bot);

        $bot->assertReplyText(__('messages.welcome_back', ['name' => 'Ali Valiyev']));
        $bot->assertNoConversation();
    }
}
