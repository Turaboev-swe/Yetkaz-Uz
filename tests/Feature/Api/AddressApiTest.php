<?php

namespace Tests\Feature\Api;

use App\Models\Address;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTelegramInitData;
use Tests\TestCase;

class AddressApiTest extends TestCase
{
    use InteractsWithTelegramInitData;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindInitDataValidator();
        Http::fake(); // geokodlash — tashqi so'rovsiz (eng yaqin tuman markazi)
        $this->user = User::factory()->create(['telegram_id' => 333000]);
    }

    private function headers(): array
    {
        return $this->initDataHeaders($this->signedInitData(['id' => $this->user->telegram_id]));
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'label' => 'Uy',
            'lat' => 41.311,
            'lng' => 69.279,
            'address_text' => 'Amir Temur 1',
        ], $overrides);
    }

    public function test_me_returns_profile_and_addresses(): void
    {
        Address::factory()->for($this->user)->default()->create(['label' => 'Uy']);
        Address::factory()->for($this->user)->create(['label' => 'Ish']);

        $this->getJson('/api/me', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.telegram_id', 333000)
            ->assertJsonCount(2, 'data.addresses')
            ->assertJsonPath('data.addresses.0.is_default', true);
    }

    public function test_first_address_becomes_default_automatically(): void
    {
        $this->postJson('/api/addresses', $this->payload(), $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.is_default', true)
            ->assertJsonPath('data.label', 'Uy');
    }

    public function test_creating_a_default_address_unsets_the_previous_default(): void
    {
        $first = Address::factory()->for($this->user)->default()->create();

        $this->postJson('/api/addresses', $this->payload(['label' => 'Ish', 'is_default' => true]), $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.is_default', true);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertSame(1, $this->user->addresses()->where('is_default', true)->count());
    }

    public function test_patch_can_switch_the_default_address(): void
    {
        $home = Address::factory()->for($this->user)->default()->create(['label' => 'Uy']);
        $work = Address::factory()->for($this->user)->create(['label' => 'Ish']);

        $this->patchJson("/api/addresses/{$work->id}", ['is_default' => true], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.is_default', true);

        $this->assertFalse($home->fresh()->is_default);
    }

    public function test_deleting_the_default_promotes_another_address(): void
    {
        $home = Address::factory()->for($this->user)->default()->create();
        $work = Address::factory()->for($this->user)->create();

        $this->deleteJson("/api/addresses/{$home->id}", [], $this->headers())->assertNoContent();

        $this->assertDatabaseMissing('addresses', ['id' => $home->id]);
        $this->assertTrue($work->fresh()->is_default);
    }

    public function test_patch_can_update_only_the_label(): void
    {
        $address = Address::factory()->for($this->user)->default()->create([
            'label' => 'Uy',
            'lat' => 41.311,
            'lng' => 69.279,
        ]);

        $this->patchJson("/api/addresses/{$address->id}", ['label' => 'Onamning uyi'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.label', 'Onamning uyi');

        $fresh = $address->fresh();
        $this->assertSame('Onamning uyi', $fresh->label);
        // Boshqa maydonlar tegilmagan.
        $this->assertEqualsWithDelta(41.311, $fresh->lat, 0.0001);
        $this->assertEqualsWithDelta(69.279, $fresh->lng, 0.0001);
    }

    public function test_deleting_the_only_address_succeeds(): void
    {
        $address = Address::factory()->for($this->user)->default()->create();

        $this->deleteJson("/api/addresses/{$address->id}", [], $this->headers())->assertNoContent();

        $this->assertDatabaseMissing('addresses', ['id' => $address->id]);
        $this->assertSame(0, $this->user->addresses()->count());
    }

    /**
     * `orders.address_id` `nullOnDelete()` va `address_snapshot` jsonb'da
     * mustaqil saqlanadi (Claude.md: "manzil keyin o'chsa ham buyurtma
     * buzilmaydi") — shuning uchun buyurtma tarixida ishlatilgan manzilni
     * o'chirish CHEKLANMAYDI, faqat FK avtomatik NULL bo'ladi.
     */
    public function test_deleting_an_address_used_in_order_history_is_safe(): void
    {
        $address = Address::factory()->for($this->user)->default()->create();
        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'address_id' => $address->id,
            'address_snapshot' => ['address_text' => $address->address_text, 'label' => $address->label],
        ]);

        $this->deleteJson("/api/addresses/{$address->id}", [], $this->headers())->assertNoContent();

        $this->assertDatabaseMissing('addresses', ['id' => $address->id]);
        $fresh = $order->fresh();
        $this->assertNull($fresh->address_id);
        $this->assertSame($address->label, $fresh->address_snapshot['label']);
    }

    public function test_cannot_touch_another_users_address(): void
    {
        $foreign = Address::factory()->create(); // boshqa foydalanuvchi

        $this->patchJson("/api/addresses/{$foreign->id}", ['label' => 'Hack'], $this->headers())->assertNotFound();
        $this->deleteJson("/api/addresses/{$foreign->id}", [], $this->headers())->assertNotFound();

        $this->assertDatabaseHas('addresses', ['id' => $foreign->id, 'label' => $foreign->label]);
    }

    public function test_validation_rejects_bad_coordinates(): void
    {
        $this->postJson('/api/addresses', $this->payload(['lat' => 200]), $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('lat');
    }

    public function test_addresses_index_only_lists_own_addresses(): void
    {
        Address::factory()->for($this->user)->count(2)->create();
        Address::factory()->count(3)->create(); // begona

        $this->getJson('/api/addresses', $this->headers())
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
