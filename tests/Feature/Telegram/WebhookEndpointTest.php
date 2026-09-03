<?php

namespace Tests\Feature\Telegram;

use Illuminate\Foundation\Testing\RefreshDatabase;
use SergiX44\Nutgram\Nutgram;
use Tests\TestCase;

/**
 * PROD-2 / PROD-6 — Telegram webhook endpoint xavfsizligi va dispatch.
 */
class WebhookEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-webhook-secret-0123456789abcdef';

    private const URL = '/api/telegram/webhook/'.self::SECRET;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('telegram.webhook_secret', self::SECRET);
    }

    /** @return array<string, mixed> */
    private function startUpdate(int $tgId = 770001): array
    {
        return [
            'update_id' => 10001,
            'message' => [
                'message_id' => 1,
                'date' => now()->timestamp,
                'chat' => ['id' => $tgId, 'type' => 'private'],
                'from' => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'Ali', 'language_code' => 'uz'],
                'text' => '/start',
            ],
        ];
    }

    public function test_wrong_path_secret_returns_404(): void
    {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::SECRET)
            ->postJson('/api/telegram/webhook/nope', $this->startUpdate())
            ->assertNotFound();
    }

    public function test_missing_header_returns_404(): void
    {
        $this->postJson(self::URL, $this->startUpdate())->assertNotFound();
    }

    public function test_wrong_header_returns_404(): void
    {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'wrong')
            ->postJson(self::URL, $this->startUpdate())
            ->assertNotFound();
    }

    public function test_empty_configured_secret_returns_404(): void
    {
        config()->set('telegram.webhook_secret', '');

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', '')
            ->postJson('/api/telegram/webhook/', $this->startUpdate())
            ->assertNotFound();
    }

    public function test_valid_secret_dispatches_update_to_handlers(): void
    {
        app(Nutgram::class)->willStartConversation();

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::SECRET)
            ->postJson(self::URL, $this->startUpdate(770042))
            ->assertNoContent();

        // /start handler foydalanuvchi yaratadi (ro'yxatdan o'tish oqimi).
        $this->assertDatabaseHas('users', ['telegram_id' => 770042]);
    }

    public function test_handler_exception_does_not_return_5xx(): void
    {
        // Handler ichidagi xato Telegram'ga 5xx bo'lib qaytmasligi kerak
        // (aks holda Telegram update'ni qayta yuboradi va webhook'ni o'chiradi).
        app(Nutgram::class)->onCommand('boom', function (): void {
            throw new \RuntimeException('boom');
        });

        $update = $this->startUpdate(770099);
        $update['message']['text'] = '/boom';

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::SECRET)
            ->postJson(self::URL, $update)
            ->assertNoContent();
    }
}
