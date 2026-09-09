<?php

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Tests\TestCase;

/**
 * Sessiya muddati tugab CSRF token eskirsa (419) — barcha panellar uchun
 * yumshoq javob: JSON so'rovga toza xabar, to'liq sahifaga errors/419 ko'rinishi.
 */
class SessionExpiredResponseTest extends TestCase
{
    private function render(string $accept, string $method = 'PATCH')
    {
        $request = Request::create('/kitchen/orders/1/advance', $method, server: [
            'HTTP_ACCEPT' => $accept,
        ]);

        return app(ExceptionHandler::class)->render($request, new TokenMismatchException('CSRF token mismatch.'));
    }

    public function test_json_request_gets_a_clean_419_message(): void
    {
        $response = $this->render('application/json');

        $this->assertSame(419, $response->getStatusCode());
        $this->assertStringContainsString('Sessiyangiz muddati tugadi', $response->getContent());
        $this->assertStringNotContainsString('CSRF', $response->getContent());
    }

    public function test_full_page_request_gets_the_friendly_419_view(): void
    {
        $response = $this->render('text/html');

        $this->assertSame(419, $response->getStatusCode());
        $this->assertStringContainsString('Sessiyangiz muddati tugadi', $response->getContent());
        $this->assertStringContainsString('location.replace', $response->getContent());
    }
}
