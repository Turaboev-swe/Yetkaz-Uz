<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithTelegramInitData;
use Tests\TestCase;

/**
 * POST /api/promo-codes/validate — checkout'dagi "Qo'llash": chegirma summasi
 * yoki aniq xato sababi (o'zbekcha matn + mashina o'qiydigan `promo_error`).
 */
class PromoCodeValidateApiTest extends TestCase
{
    use InteractsWithTelegramInitData;
    use RefreshDatabase;

    private User $user;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindInitDataValidator();
        Http::fake();
        Carbon::setTestNow('2026-09-28 12:00:00');

        $this->user = User::factory()->create(['telegram_id' => 700700, 'language' => 'uz']);
        $this->restaurant = Restaurant::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function validatePromo(string $code, int $subtotal = 69_000_00, ?int $restaurantId = null)
    {
        return $this->postJson('/api/promo-codes/validate', [
            'promo_code' => $code,
            'restaurant_id' => $restaurantId ?? $this->restaurant->id,
            'subtotal' => $subtotal,
        ], $this->initDataHeaders($this->signedInitData(['id' => $this->user->telegram_id])));
    }

    public function test_valid_code_returns_the_discount_amount(): void
    {
        PromoCode::factory()->percent(20)->create(['code' => 'OSON50']);

        $this->validatePromo('oson50')
            ->assertOk()
            ->assertJsonPath('data.code', 'OSON50')
            ->assertJsonPath('data.discount_amount', 13_800_00); // 69 000 so'm * 20%
    }

    public function test_validate_does_not_create_anything(): void
    {
        PromoCode::factory()->create(['code' => 'OSON50']);

        $this->validatePromo('OSON50')->assertOk();

        $this->assertDatabaseCount('orders', 0);
    }

    /** @return array<string, array{string, string}> */
    public static function reasons(): array
    {
        return [
            'topilmadi' => ['not_found', 'Bunday promokod topilmadi.'],
            'faol emas' => ['inactive', 'Bu promokod hozir faol emas.'],
            'boshlanmagan' => ['not_started', 'Bu promokod hali kuchga kirmagan.'],
            'muddati o\'tgan' => ['expired', "Promokodning amal qilish muddati o'tgan."],
            'boshqa restoran' => ['wrong_restaurant', 'Bu promokod ushbu restoranda ishlamaydi.'],
            'umumiy limit' => ['usage_limit_reached', 'Promokodning ishlatilish limiti tugagan.'],
            'mijoz limiti' => ['user_limit_reached', "Siz bu promokoddan ruxsat etilgan marta foydalanib bo'lgansiz."],
        ];
    }

    #[DataProvider('reasons')]
    public function test_each_rejection_reason_is_reported_precisely_in_uzbek(string $reason, string $message): void
    {
        $code = match ($reason) {
            'not_found' => 'YOQ-BUNDAY',
            'inactive' => PromoCode::factory()->inactive()->create()->code,
            'not_started' => PromoCode::factory()->create(['starts_at' => now()->addDay()])->code,
            'expired' => PromoCode::factory()->create(['ends_at' => now()->subDay()])->code,
            'wrong_restaurant' => PromoCode::factory()->create(['restaurant_id' => Restaurant::factory()->create()->id])->code,
            'usage_limit_reached' => $this->usedUp(['total_usage_limit' => 1], User::factory()->create()),
            'user_limit_reached' => $this->usedUp(['per_user_limit' => 1], $this->user),
        };

        $this->validatePromo($code)
            ->assertStatus(422)
            ->assertJsonPath('code', 'promo_code_invalid')
            ->assertJsonPath('promo_error', $reason)
            ->assertJsonPath('message', $message)
            ->assertJsonPath('errors.promo_code.0', $message)
            ->assertJsonMissingPath('reason'); // Mini App api.js 'reason'ni xabarга qo'shib yuborardi
    }

    public function test_message_follows_the_users_chosen_language(): void
    {
        $this->user->update(['language' => 'ru']);

        $this->validatePromo('YOQ-BUNDAY')
            ->assertStatus(422)
            ->assertJsonPath('promo_error', 'not_found')
            ->assertJsonPath('message', 'Такой промокод не найден.');
    }

    public function test_cancelled_orders_do_not_use_up_the_limit(): void
    {
        $promo = PromoCode::factory()->create(['per_user_limit' => 1]);
        Order::factory()->for($this->user)->create(['promo_code_id' => $promo->id, 'status' => OrderStatus::Cancelled]);

        $this->validatePromo($promo->code)->assertOk();
    }

    public function test_input_is_validated(): void
    {
        $this->postJson('/api/promo-codes/validate', ['promo_code' => 'X'], $this->initDataHeaders(
            $this->signedInitData(['id' => $this->user->telegram_id]),
        ))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['restaurant_id', 'subtotal']);
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/promo-codes/validate', [
            'promo_code' => 'OSON50', 'restaurant_id' => $this->restaurant->id, 'subtotal' => 1_000_00,
        ])->assertUnauthorized();
    }

    /** @param  array<string, mixed>  $limits */
    private function usedUp(array $limits, User $by): string
    {
        $promo = PromoCode::factory()->create($limits);
        Order::factory()->for($by)->create(['promo_code_id' => $promo->id, 'status' => OrderStatus::New]);

        return $promo->code;
    }
}
