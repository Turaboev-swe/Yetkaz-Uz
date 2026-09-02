<?php

namespace Tests\Feature\Telegram;

use App\Telegram\RedactingBotClientHandler;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * PROD-4 token redaktsiyasi + tarmoq beqarorligiga qarshi ConnectException retry.
 */
class RedactingBotClientHandlerTest extends TestCase
{
    private const URL = 'https://api.telegram.org/bot8958318565:AAGLXULeKIOKVmkEcBiwP44d9k7lG9ZNkEI/getUpdates';

    private function connectException(): ConnectException
    {
        return new ConnectException(
            'cURL error 28: Operation timed out for '.self::URL,
            new Request('POST', self::URL),
        );
    }

    public function test_connect_exception_is_redacted_after_retries_exhausted(): void
    {
        // Boshlang'ich + 2 retry = 3 marta uziladi.
        $mock = new MockHandler([
            $this->connectException(),
            $this->connectException(),
            $this->connectException(),
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

    public function test_recovers_when_a_retry_succeeds(): void
    {
        // 2 marta uziladi, 3-urinishda muvaffaqiyat.
        $mock = new MockHandler([
            $this->connectException(),
            $this->connectException(),
            new Response(200, [], '{"ok":true}'),
        ]);
        $client = new Client(['handler' => RedactingBotClientHandler::stack($mock)]);

        $this->assertSame('{"ok":true}', (string) $client->post(self::URL)->getBody());
    }

    public function test_successful_request_passes_through_without_retry(): void
    {
        $mock = new MockHandler([new Response(200, [], '{"ok":true}')]);
        $client = new Client(['handler' => RedactingBotClientHandler::stack($mock)]);

        $this->assertSame('{"ok":true}', (string) $client->post(self::URL)->getBody());
        $this->assertCount(0, $mock); // navbatda boshqa javob qolmagan
    }

    public function test_http_error_response_is_not_retried(): void
    {
        // Telegram 400 (masalan "chat not found") — bu ConnectException emas,
        // qayta urilmasligi kerak.
        $mock = new MockHandler([new Response(400, [], '{"ok":false,"description":"Bad Request"}')]);
        $client = new Client(['handler' => RedactingBotClientHandler::stack($mock), 'http_errors' => false]);

        $this->assertSame(400, $client->post(self::URL)->getStatusCode());
        $this->assertCount(0, $mock);
    }
}
