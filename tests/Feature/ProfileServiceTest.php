<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\User;
use App\Services\User\ProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProfileService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProfileService::class);
    }

    public function test_creates_user_on_first_contact(): void
    {
        $user = $this->service->findOrCreateFromTelegram(telegramId: 12345, languageCode: 'ru-RU');

        $this->assertDatabaseHas('users', [
            'telegram_id' => 12345,
            'language' => 'uz', // Telegram tili ru bo'lsa ham — standart o'zbekcha
            'profile_completed' => false,
        ]);
        $this->assertNull($user->phone);
        $this->assertNull($user->full_name);
    }

    public function test_returns_existing_user_without_duplicating(): void
    {
        $existing = User::factory()->create(['telegram_id' => 777]);

        $again = $this->service->findOrCreateFromTelegram(telegramId: 777, languageCode: 'en');

        $this->assertSame($existing->id, $again->id);
        $this->assertSame(1, User::where('telegram_id', 777)->count());
    }

    public function test_language_always_defaults_to_uz_regardless_of_telegram_locale(): void
    {
        $this->service->findOrCreateFromTelegram(telegramId: 1, languageCode: 'en');
        $this->service->findOrCreateFromTelegram(telegramId: 2, languageCode: null);
        $this->service->findOrCreateFromTelegram(telegramId: 3, languageCode: 'ru');

        $this->assertSame('uz', User::byTelegramId(1)->value('language'));
        $this->assertSame('uz', User::byTelegramId(2)->value('language'));
        $this->assertSame('uz', User::byTelegramId(3)->value('language'));
    }

    public function test_normalizes_phone_from_contact(): void
    {
        $user = User::factory()->incomplete()->create();

        $this->service->saveContact($user, '998 90 123 45 67');

        $this->assertSame('+998901234567', $user->fresh()->phone);
    }

    public function test_normalizes_phone_that_already_has_plus(): void
    {
        $user = User::factory()->incomplete()->create();

        $this->service->saveContact($user, '+998901234567');

        $this->assertSame('+998901234567', $user->fresh()->phone);
    }

    public function test_cleans_whitespace_in_name(): void
    {
        $user = User::factory()->incomplete()->create();

        $this->service->saveName($user, '  Ali   Valiyev  ');

        $this->assertSame('Ali Valiyev', $user->fresh()->full_name);
    }

    public function test_complete_with_home_address_saves_default_address_and_marks_profile(): void
    {
        $user = User::factory()->incomplete()->create();
        $this->service->saveContact($user, '998901112233');
        $this->service->saveName($user, 'Ali');

        $address = $this->service->completeWithHomeAddress($user, 41.311, 69.279);

        $this->assertSame(Address::LABEL_HOME, $address->label);
        $this->assertTrue($address->is_default);
        $this->assertSame($user->id, $address->user_id);
        $this->assertTrue($user->fresh()->profile_completed);
        $this->assertTrue($this->service->isRegistered($user->fresh()));
    }

    public function test_address_text_falls_back_to_coordinates(): void
    {
        $user = User::factory()->incomplete()->create();

        $address = $this->service->completeWithHomeAddress($user, 41.3111111, 69.2799999);

        $this->assertSame('41.311111, 69.280000', $address->address_text);
    }

    public function test_is_registered_false_until_all_three_pieces_present(): void
    {
        $user = User::factory()->incomplete()->create();
        $this->assertFalse($this->service->isRegistered($user));

        $this->service->saveContact($user, '998901112233');
        $this->assertFalse($this->service->isRegistered($user->fresh()));

        $this->service->saveName($user, 'Ali');
        $this->assertFalse($this->service->isRegistered($user->fresh()));

        $this->service->completeWithHomeAddress($user, 41.3, 69.2);
        $this->assertTrue($this->service->isRegistered($user->fresh()));
    }
}
