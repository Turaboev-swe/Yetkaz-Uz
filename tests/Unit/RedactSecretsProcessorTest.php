<?php

namespace Tests\Unit;

use App\Logging\RedactSecretsProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class RedactSecretsProcessorTest extends TestCase
{
    public function test_redacts_message_and_context(): void
    {
        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'test',
            level: Level::Error,
            message: 'cURL error for https://api.telegram.org/bot8958318565:AAGLXULeKIOKVmkEcBiwP44d9k7lG9ZNkEI/getUpdates',
            context: ['url' => 'https://api.telegram.org/bot8958318565:AAGLXULeKIOKVmkEcBiwP44d9k7lG9ZNkEI/sendMessage'],
        );

        $out = (new RedactSecretsProcessor)($record);

        $this->assertStringNotContainsString('AAGLXULeKIOKVmkEcBiwP44d9k7lG9ZNkEI', $out->message);
        $this->assertStringNotContainsString('AAGLXULeKIOKVmkEcBiwP44d9k7lG9ZNkEI', $out->context['url']);
        $this->assertStringContainsString('bot8958318565:[REDACTED]', $out->message);
    }
}
