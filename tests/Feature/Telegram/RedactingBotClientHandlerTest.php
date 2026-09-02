<?php

namespace Tests\Feature\Telegram;

use App\Telegram\RedactingBotClientHandler;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

/**
 * PROD-4 — Nutgram Guzzle mijozidagi transport xatolaridan token yashiriladi.
 */
class RedactingBotClientHandlerTest extends TestCase
{
    private const URL = 'https://api.telegram.org/bot8958318565:AAGLXULeKIOKVmkEcBiwP44d9k7lG9ZNkEI/getUpdates';

    public function test_connect_exception_message_is_redacted_and_type_preserved(): void
    {
        $mock = new MockHandler([
            new ConnectException(
                'cURL error 18: HTTP/2 stream was not closed cleanly for '.self::URL,
                new Request('POST', self::URL),
            ),
        ]);

        $client = new Client(['handler' => RedactingBotClientHandler::stack($mock)]);

        try {
            $client->post(self::URL);
            $this->fail('ConnectException kutilgan edi.');
        } catch (ConnectException $e) {
            $this->assertStringNotContainsString('AAGLXULeKIOKVmkEcBiwP44d9k7lG9ZNkEI', $e->getMessage());
            $this->assertStringContainsString('bot8958318565:[REDACTED]', $e->getMessage());
        }
    }

    public function test_successful_request_passes_through(): void
    {
        $mock = new MockHandler([new \GuzzleHttp\Psr7\Response(200, [], '{"ok":true}')]);
        $client = new Client(['handler' => RedactingBotClientHandler::stack($mock)]);

        $this->assertSame('{"ok":true}', (string) $client->post(self::URL)->getBody());
    }
}
