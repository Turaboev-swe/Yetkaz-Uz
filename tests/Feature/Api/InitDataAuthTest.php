<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTelegramInitData;
use Tests\TestCase;

class InitDataAuthTest extends TestCase
{
    use InteractsWithTelegramInitData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindInitDataValidator();
    }

    public function test_valid_init_data_authenticates_and_creates_the_user(): void
    {
        $initData = $this->signedInitData(['id' => 42424242, 'language_code' => 'ru', 'username' => 'ali_dev']);

        $this->getJson('/api/me', $this->initDataHeaders($initData))
            ->assertOk()
            ->assertJsonPath('data.telegram_id', 42424242);

        // Standart til — o'zbekcha (Telegram tili ru bo'lsa ham). @username saqlanadi.
        $this->assertDatabaseHas('users', [
            'telegram_id' => 42424242,
            'language' => 'uz',
            'username' => 'ali_dev',
        ]);
    }

    public function test_missing_init_data_is_rejected(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_tampered_signature_is_rejected(): void
    {
        $initData = $this->signedInitData().'&x=injected';

        $this->getJson('/api/me', $this->initDataHeaders($initData))
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Telegram initData tekshiruvidan o\'tmadi.');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_wrong_hash_is_rejected(): void
    {
        $initData = preg_replace('/hash=[a-f0-9]+/', 'hash='.str_repeat('0', 64), $this->signedInitData());

        $this->getJson('/api/me', $this->initDataHeaders($initData))->assertUnauthorized();
    }

    public function test_expired_auth_date_is_rejected(): void
    {
        $initData = $this->signedInitData(authDate: time() - 86_401);

        $this->getJson('/api/me', $this->initDataHeaders($initData))
            ->assertUnauthorized()
            ->assertJsonPath('reason', fn ($r) => str_contains((string) $r, 'eskirgan'));
    }

    public function test_init_data_within_ttl_is_accepted(): void
    {
        $initData = $this->signedInitData(authDate: time() - 3600);

        $this->getJson('/api/me', $this->initDataHeaders($initData))->assertOk();
    }

    public function test_x_telegram_init_data_header_also_works(): void
    {
        $initData = $this->signedInitData();

        $this->getJson('/api/me', ['X-Telegram-Init-Data' => $initData])->assertOk();
    }

    public function test_signature_field_is_part_of_hmac_check(): void
    {
        // Telegram `signature` (Ed25519) ni HMAC `hash` data_check_string'iga KIRITADI.
        // signedInitData() standart holda `signature` beradi — u bilan imzo to'g'ri.
        $ok = $this->signedInitData(['id' => 55501], extra: ['signature' => 'sig_abc123']);
        $this->getJson('/api/me', $this->initDataHeaders($ok))->assertOk();

        // Imzolangandan keyin `signature` ni buzsak — hash endi mos kelmaydi.
        $tampered = preg_replace('/signature=[^&]+/', 'signature=sig_TAMPERED', $ok);
        $this->getJson('/api/me', $this->initDataHeaders($tampered))
            ->assertUnauthorized()
            ->assertJsonPath('reason', fn ($r) => str_contains((string) $r, 'imzosi'));
    }

    public function test_authenticated_request_reuses_existing_user(): void
    {
        $user = User::factory()->create(['telegram_id' => 999000, 'full_name' => 'Bor Foydalanuvchi']);
        $initData = $this->signedInitData(['id' => 999000]);

        $this->getJson('/api/me', $this->initDataHeaders($initData))
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.full_name', 'Bor Foydalanuvchi');

        $this->assertSame(1, User::where('telegram_id', 999000)->count());
    }
}
