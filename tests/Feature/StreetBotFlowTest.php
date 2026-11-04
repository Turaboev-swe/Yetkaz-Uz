<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\District;
use App\Models\Region;
use App\Models\User;
use App\Telegram\Support\Keyboards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use Tests\TestCase;

/**
 * Bot: lokatsiyadan keyin geokoder ko'cha topmasa ko'cha YOKI mo'ljal so'raladi (o'tkazib yuborib bo'lmaydi).
 * Ikki oqim: ro'yxatdan o'tish (lokatsiya majburiy — bekor qilish yo'q) va «📍 Yangi manzil» (bekor qilish bor).
 */
class StreetBotFlowTest extends TestCase
{
    use RefreshDatabase;

    private const TG_ID = 555900;

    /** Nominatim javobi (address bo'laklari): test ichida o'zgartiriladi. */
    private array $nominatim = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::fake(fn () => Http::response(['address' => $this->nominatim + ['county' => "Qo'rg'ontepa tumani"]]));

        $region = Region::factory()->create(['code' => 'AN']);
        District::factory()->for($region)->create(['name' => "Qo'rg'ontepa tumani", 'center_lat' => 40.7278, 'center_lng' => 72.7629]);
    }

    private function bot(): Nutgram
    {
        /** @var Nutgram $bot */
        $bot = app(Nutgram::class);
        $bot->willStartConversation();

        return $bot;
    }

    private function say(Nutgram $bot, string $text): void
    {
        $bot->hearMessage(['from' => ['id' => self::TG_ID, 'first_name' => 'Ali'], 'text' => $text])->reply();
    }

    private function sendLocation(Nutgram $bot, float $lat = 40.7278, float $lng = 72.7629): void
    {
        $bot->hearMessage(['from' => ['id' => self::TG_ID, 'first_name' => 'Ali'], 'location' => ['latitude' => $lat, 'longitude' => $lng]])->reply();
    }

    /** Ro'yxatdan o'tish: telefon -> ism -> (lokatsiya tugmasi kutilmoqda). */
    private function registrationUntilLocation(Nutgram $bot): void
    {
        $bot->hearMessage(['from' => ['id' => self::TG_ID, 'first_name' => 'Ali', 'language_code' => 'uz'], 'text' => '/start'])->reply();
        $bot->hearMessage(['contact' => ['phone_number' => '998901234567', 'first_name' => 'Ali', 'user_id' => self::TG_ID]])->reply();
        $this->say($bot, 'Ali Valiyev');
    }

    private function registered(string $language = 'uz'): User
    {
        $user = User::factory()->create([
            'telegram_id' => self::TG_ID, 'full_name' => 'Ali Valiyev', 'phone' => '+998901234567',
            'language' => $language, 'profile_completed' => true,
        ]);
        Address::factory()->for($user)->default()->create(['label' => 'Uy', 'address_text' => 'Eski manzil', 'resolved_address' => 'Eski manzil']);

        return $user;
    }

    private function newest(User $user): Address
    {
        return $user->addresses()->latest('id')->firstOrFail();
    }

    // === Ro'yxatdan o'tish ===============================================================================

    public function test_registration_does_not_ask_when_the_geocoder_finds_a_street(): void
    {
        $this->nominatim = ['road' => "Bobur ko'chasi", 'house_number' => '12'];
        $bot = $this->bot();
        $this->registrationUntilLocation($bot);

        $this->sendLocation($bot);

        $user = User::byTelegramId(self::TG_ID)->firstOrFail();
        $this->assertTrue($user->profile_completed);
        $this->assertSame("Bobur ko'chasi 12", $user->addresses()->sole()->street);
        $bot->assertNoConversation();
    }

    public function test_registration_asks_for_a_street_when_the_geocoder_finds_none(): void
    {
        $bot = $this->bot();
        $this->registrationUntilLocation($bot);

        $this->sendLocation($bot);

        $bot->assertReplyText(__('messages.address_flow.ask_street'));
        $bot->assertActiveConversation();
        $user = User::byTelegramId(self::TG_ID)->firstOrFail();
        $this->assertFalse($user->profile_completed, 'ko‘chasiz ro‘yxatdan o‘tish tugamaydi');
        $this->assertSame(0, $user->addresses()->count());
    }

    public function test_registration_completes_with_the_typed_street(): void
    {
        $bot = $this->bot();
        $this->registrationUntilLocation($bot);
        $this->sendLocation($bot);

        $this->say($bot, "Bobur ko'chasi 12");

        $user = User::byTelegramId(self::TG_ID)->firstOrFail();
        $this->assertTrue($user->profile_completed);
        $address = $user->addresses()->sole();
        $this->assertSame("Bobur ko'chasi 12", $address->street);
        $this->assertSame("Bobur ko'chasi 12, Qo'rg'ontepa tumani", $address->address_text);
        $this->assertSame(Address::LABEL_HOME, $address->label);
        $this->assertTrue($address->is_default);
        $bot->assertNoConversation();
    }

    public function test_registration_with_dont_know_the_street_asks_for_a_landmark(): void
    {
        $this->nominatim = ['village' => 'Yangi hayot'];
        $bot = $this->bot();
        $this->registrationUntilLocation($bot);
        $this->sendLocation($bot);

        $this->say($bot, __('messages.address_flow.dont_know_street'));
        $bot->assertReplyText(__('messages.address_flow.ask_landmark'));
        $bot->assertActiveConversation();

        $this->say($bot, 'Maktab yonida, ko‘k darvoza');

        $user = User::byTelegramId(self::TG_ID)->firstOrFail();
        $this->assertTrue($user->profile_completed);
        $address = $user->addresses()->sole();
        $this->assertNull($address->street);
        $this->assertSame('Maktab yonida, ko‘k darvoza', $address->note);
        $this->assertSame("Yangi hayot, Qo'rg'ontepa tumani", $address->address_text);
        $bot->assertNoConversation();
    }

    public function test_registration_treats_a_route_number_as_no_street(): void
    {
        $this->nominatim = ['road' => 'D137'];
        $bot = $this->bot();
        $this->registrationUntilLocation($bot);

        $this->sendLocation($bot);

        $bot->assertReplyText(__('messages.address_flow.ask_street'));
        $bot->assertActiveConversation();
    }

    public function test_registration_rejects_too_short_text_and_menu_buttons(): void
    {
        $bot = $this->bot();
        $this->registrationUntilLocation($bot);
        $this->sendLocation($bot);

        foreach (['ab', '  ', __('messages.main_menu.order'), __('messages.main_menu.restaurants')] as $bad) {
            $this->say($bot, $bad);
            $bot->assertReplyText(__('messages.address_flow.too_short'));
            $bot->assertActiveConversation();
        }

        $this->assertSame(0, User::byTelegramId(self::TG_ID)->firstOrFail()->addresses()->count());
    }

    public function test_registration_rejects_a_message_without_text(): void
    {
        $bot = $this->bot();
        $this->registrationUntilLocation($bot);
        $this->sendLocation($bot);

        $bot->hearMessage(['from' => ['id' => self::TG_ID, 'first_name' => 'Ali'], 'sticker' => ['file_id' => 'x', 'file_unique_id' => 'y', 'type' => 'regular', 'width' => 1, 'height' => 1, 'is_animated' => false, 'is_video' => false]])->reply();

        $bot->assertReplyText(__('messages.address_flow.too_short'));
        $bot->assertActiveConversation();
    }

    public function test_registration_uses_the_new_point_when_a_location_is_resent_during_the_question(): void
    {
        $bot = $this->bot();
        $this->registrationUntilLocation($bot);
        $this->sendLocation($bot);                       // ko'cha yo'q -> so'raladi

        $this->nominatim = ['road' => "Navoiy ko'chasi"];   // yangi nuqtada ko'cha bor
        $this->sendLocation($bot, 40.7300, 72.7700);

        $user = User::byTelegramId(self::TG_ID)->firstOrFail();
        $this->assertTrue($user->profile_completed);
        $address = $user->addresses()->sole();
        $this->assertEqualsWithDelta(40.73, $address->lat, 0.0001);
        $this->assertSame("Navoiy ko'chasi", $address->street);
        $bot->assertNoConversation();
    }

    public function test_the_registration_prompt_has_no_skip_and_no_cancel_button(): void
    {
        $buttons = $this->buttonTexts(Keyboards::streetPrompt(false));

        $this->assertSame([__('messages.address_flow.dont_know_street')], $buttons);
        $this->assertNotContains(__('messages.address_flow.cancel'), $buttons);
    }

    // === «📍 Yangi manzil» (ro'yxatdan o'tgan mijoz) ======================================================

    public function test_a_found_street_saves_the_address_without_any_question(): void
    {
        $this->nominatim = ['road' => "Navoiy ko'chasi", 'hamlet' => 'Gulobod'];
        $user = $this->registered();
        $bot = $this->bot();

        $this->sendLocation($bot);

        $this->assertSame(2, $user->addresses()->count());
        $new = $this->newest($user);
        $this->assertSame("Navoiy ko'chasi", $new->street);
        $this->assertSame("Navoiy ko'chasi, Gulobod, Qo'rg'ontepa tumani", $new->address_text);
        $this->assertTrue($new->is_default);
        $bot->assertReplyText(__('messages.addresses.added', ['address' => $new->displayAddress()]));
        $bot->assertNoConversation();
    }

    public function test_a_missing_street_asks_once_and_saves_nothing_yet(): void
    {
        $user = $this->registered();
        $bot = $this->bot();

        $this->sendLocation($bot);

        $bot->assertReplyText(__('messages.address_flow.ask_street'));
        $bot->assertActiveConversation();
        $this->assertSame(1, $user->addresses()->count());
    }

    public function test_the_typed_street_is_saved_as_the_new_default(): void
    {
        $user = $this->registered();
        $bot = $this->bot();
        $this->sendLocation($bot);

        $this->say($bot, "Fidoyi ko'chasi 5");

        $this->assertSame(2, $user->addresses()->count());
        $new = $this->newest($user);
        $this->assertSame("Fidoyi ko'chasi 5", $new->street);
        $this->assertSame("Fidoyi ko'chasi 5, Qo'rg'ontepa tumani", $new->address_text);
        $this->assertSame(__('messages.addresses.label', ['n' => 2]), $new->label);
        $this->assertTrue($new->is_default);
        $this->assertSame(1, $user->addresses()->where('is_default', true)->count(), 'eski manzil asosiy emas');
        $bot->assertReplyText(__('messages.addresses.added', ['address' => $new->displayAddress()]));
        $bot->assertNoConversation();
    }

    public function test_dont_know_the_street_then_a_landmark_is_saved(): void
    {
        $user = $this->registered();
        $bot = $this->bot();
        $this->sendLocation($bot);

        $this->say($bot, __('messages.address_flow.dont_know_street'));
        $bot->assertReplyText(__('messages.address_flow.ask_landmark'));
        $this->say($bot, 'Bozor yonida');

        $new = $this->newest($user);
        $this->assertNull($new->street);
        $this->assertSame('Bozor yonida', $new->note);
        $this->assertSame("Qo'rg'ontepa tumani", $new->address_text);
        $bot->assertNoConversation();
    }

    public function test_cancelling_saves_nothing(): void
    {
        $user = $this->registered();
        $bot = $this->bot();
        $this->sendLocation($bot);

        $this->say($bot, __('messages.address_flow.cancel'));

        $this->assertSame(1, $user->addresses()->count());
        $bot->assertReplyText(__('messages.address_flow.cancelled'));
        $bot->assertNoConversation();
    }

    public function test_cancelling_at_the_landmark_step_saves_nothing_too(): void
    {
        $user = $this->registered();
        $bot = $this->bot();
        $this->sendLocation($bot);
        $this->say($bot, __('messages.address_flow.dont_know_street'));

        $this->say($bot, __('messages.address_flow.cancel'));

        $this->assertSame(1, $user->addresses()->count());
        $bot->assertNoConversation();
    }

    public function test_the_new_address_prompt_offers_dont_know_and_cancel_but_never_skip(): void
    {
        $buttons = $this->buttonTexts(Keyboards::streetPrompt(true));

        $this->assertSame([__('messages.address_flow.dont_know_street'), __('messages.address_flow.cancel')], $buttons);
        $this->assertSame([__('messages.address_flow.cancel')], $this->buttonTexts(Keyboards::cancelOnly()));
    }

    public function test_a_resent_location_replaces_the_pending_point(): void
    {
        $user = $this->registered();
        $bot = $this->bot();
        $this->sendLocation($bot);

        $this->sendLocation($bot, 40.7300, 72.7700);          // hali ko'cha yo'q -> yana so'raydi
        $bot->assertReplyText(__('messages.address_flow.ask_street'));
        $this->say($bot, 'Navoiy 7');

        $new = $this->newest($user);
        $this->assertEqualsWithDelta(40.73, $new->lat, 0.0001);
        $this->assertEqualsWithDelta(72.77, $new->lng, 0.0001);
    }

    public function test_too_short_and_menu_texts_do_not_save(): void
    {
        $user = $this->registered();
        $bot = $this->bot();
        $this->sendLocation($bot);

        foreach (['a', __('messages.main_menu.settings')] as $bad) {
            $this->say($bot, $bad);
            $bot->assertActiveConversation();
        }

        $this->assertSame(1, $user->addresses()->count());
    }

    public function test_the_questions_follow_the_customers_language(): void
    {
        $this->registered('ru');
        $bot = $this->bot();

        $this->sendLocation($bot);

        $bot->assertReplyText(__('messages.address_flow.ask_street', [], 'ru'));
    }

    /** @return list<string> */
    private function buttonTexts($keyboard): array
    {
        return collect($keyboard->keyboard)->flatten(1)->map(fn ($button) => $button->text)->values()->all();
    }
}
