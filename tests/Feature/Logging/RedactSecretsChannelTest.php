<?php

namespace Tests\Feature\Logging;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * PROD-4 — log kanallariga ulangan sir-yashirish.
 */
class RedactSecretsChannelTest extends TestCase
{
    public function test_single_and_daily_channels_have_the_redacting_tap(): void
    {
        $this->assertContains(\App\Logging\RedactSecretsTap::class, config('logging.channels.single.tap', []));
        $this->assertContains(\App\Logging\RedactSecretsTap::class, config('logging.channels.daily.tap', []));
    }

    public function test_token_is_redacted_in_written_log_line(): void
    {
        $path = storage_path('logs/redact-test-'.uniqid().'.log');

        config()->set('nutgram.token', '8958318565:AAGLXULeKIOKVmkEcBiwP44d9k7lG9ZNkEI');
        config()->set('logging.channels.redact_test', [
            'driver' => 'single',
            'path' => $path,
            'tap' => [\App\Logging\RedactSecretsTap::class],
        ]);

        Log::channel('redact_test')->error('sendMessage failed: https://api.telegram.org/bot8958318565:AAGLXULeKIOKVmkEcBiwP44d9k7lG9ZNkEI/sendMessage', [
            'token' => '8958318565:AAGLXULeKIOKVmkEcBiwP44d9k7lG9ZNkEI',
        ]);

        $contents = file_get_contents($path);
        @unlink($path);

        $this->assertStringNotContainsString('AAGLXULeKIOKVmkEcBiwP44d9k7lG9ZNkEI', $contents);
        $this->assertStringContainsString('[REDACTED]', $contents);
    }
}
