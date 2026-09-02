<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTelegramInitData;
use Tests\TestCase;

/**
 * PROD-1 — Mini App API rate limiting.
 */
class RateLimitTest extends TestCase
{
    use InteractsWithTelegramInitData;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindInitDataValidator();
        Http::fake();

        $this->user = User::factory()->create(['telegram_id' => 900901]);
    }

    /** @return array<string, string> */
    private function headersFor(int $telegramId): array
    {
        return $this->initDataHeaders($this->signedInitData(['id' => $telegramId]));
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->headersFor($this->user->telegram_id);
    }

    public function test_read_endpoints_allow_60_per_minute_then_429(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/regions', $this->headers())->assertOk();
        }

        $this->getJson('/api/regions', $this->headers())
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', __('messages.rate_limited'));
    }

    public function test_order_endpoint_allows_5_per_minute_then_429(): void
    {
        for ($i = 0; $i < 5; $i++) {
            // Bo'sh payload — validatsiya 422 beradi, lekin limit EMAS.
            $this->postJson('/api/orders', [], $this->headers())->assertStatus(422);
        }

        $this->postJson('/api/orders', [], $this->headers())
            ->assertStatus(429)
            ->assertJsonPath('message', __('messages.rate_limited_orders'));
    }

    public function test_address_writes_allow_20_per_minute_then_429(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/addresses', [], $this->headers())->assertStatus(422);
        }

        $this->postJson('/api/addresses', [], $this->headers())
            ->assertStatus(429)
            ->assertJsonPath('message', __('messages.rate_limited'));
    }

    public function test_order_limit_is_per_user(): void
    {
        $other = User::factory()->create(['telegram_id' => 900902]);

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/orders', [], $this->headers());
        }
        $this->postJson('/api/orders', [], $this->headers())->assertStatus(429);

        // Boshqa foydalanuvchining limiti alohida — u hali ham o'tadi.
        $this->postJson('/api/orders', [], $this->headersFor($other->telegram_id))
            ->assertStatus(422);
    }

    public function test_read_and_order_limits_are_independent(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/orders', [], $this->headers());
        }
        $this->postJson('/api/orders', [], $this->headers())->assertStatus(429);

        // Buyurtma limiti tugagani o'qish endpointiga ta'sir qilmaydi.
        $this->getJson('/api/regions', $this->headers())->assertOk();
    }
}
