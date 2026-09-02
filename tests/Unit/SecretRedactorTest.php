<?php

namespace Tests\Unit;

use App\Support\SecretRedactor;
use PHPUnit\Framework\TestCase;

class SecretRedactorTest extends TestCase
{
    public function test_redacts_bot_token_inside_a_url(): void
    {
        $line = 'cURL error 18: stream reset for '
            .'https://api.telegram.org/bot8958318565:AAGLXULeKIOKVmkEcBiwP44d9k7lG9ZNkEI/getUpdates';

        $out = SecretRedactor::text($line);

        $this->assertStringNotContainsString('AAGLXULeKIOKVmkEcBiwP44d9k7lG9ZNkEI', $out);
        $this->assertStringContainsString('bot8958318565:[REDACTED]/getUpdates', $out);
    }

    public function test_redacts_token_with_bot_prefix_and_colon(): void
    {
        $out = SecretRedactor::text('bot123456789:SECRETSECRETSECRETSECRET1234 failed');

        $this->assertStringNotContainsString('SECRETSECRETSECRETSECRET1234', $out);
        $this->assertStringContainsString('bot123456789:[REDACTED]', $out);
    }

    public function test_leaves_ordinary_text_untouched(): void
    {
        $line = 'GET /api/restaurants 200 in 12ms';

        $this->assertSame($line, SecretRedactor::text($line));
    }

    public function test_walks_nested_arrays_and_keeps_non_strings(): void
    {
        $out = SecretRedactor::array([
            'url' => 'https://api.telegram.org/bot123456789:SECRETSECRETSECRETSECRET1234/sendMessage',
            'nested' => ['msg' => 'bot123456789:SECRETSECRETSECRETSECRET1234 failed', 'code' => 400],
        ]);

        $this->assertStringContainsString('[REDACTED]', $out['url']);
        $this->assertStringContainsString('[REDACTED]', $out['nested']['msg']);
        $this->assertSame(400, $out['nested']['code']);
    }
}
