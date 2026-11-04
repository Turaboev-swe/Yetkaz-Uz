<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Mini App HTML (index) KESHLANMASLIGI kerak: asset'lar hash'li (Vite), shuning uchun ular keshlanaveradi, lekin HTML har
 * ochilganda yangi bo'lishi shart — aks holda deploydan keyin mijoz eski JS'li eski HTML'ni ko'rib, yangi API talabini
 * (masalan manzilda ko'cha) bajara olmaydi. Laravel standarti: `Cache-Control: no-cache, private`.
 */
class MiniAppCacheHeadersTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function miniAppPaths(): array
    {
        return [
            'bosh sahifa' => ['/app'],
            'ichki yo‘l (SPA)' => ['/app/new-address'],
            'chuqur yo‘l' => ['/app/r/5/menu'],
        ];
    }

    #[DataProvider('miniAppPaths')]
    public function test_the_miniapp_html_is_never_cached(string $path): void
    {
        $this->withoutVite();

        $response = $this->get($path)->assertOk();

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringNotContainsString('max-age', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
        $this->assertStringNotContainsString('immutable', $cacheControl);

        // Shartli so'rov (304) ham bo'lmasin: ETag / Last-Modified yo'q — brauzer har safar to'liq HTML oladi.
        $this->assertNull($response->headers->get('ETag'));
        $this->assertNull($response->headers->get('Last-Modified'));
        $this->assertNull($response->headers->get('Expires'));
    }

    public function test_a_repeat_request_with_a_validator_header_still_gets_fresh_html(): void
    {
        $this->withoutVite();

        $this->get('/app', ['If-None-Match' => '"abc"', 'If-Modified-Since' => 'Wed, 01 Jan 2030 00:00:00 GMT'])->assertOk();
    }
}
