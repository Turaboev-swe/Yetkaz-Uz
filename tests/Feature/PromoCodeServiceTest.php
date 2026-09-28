<?php

namespace Tests\Feature;

use App\Models\PromoCode;
use App\Models\Restaurant;
use App\Services\Ordering\PromoCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * PromoCodeService — chegirmani hisoblash, platforma/restoran ulushiga
 * bo'lish (aniq yaxlitlash qoidasi bilan) va promokodni tekshirish.
 */
class PromoCodeServiceTest extends TestCase
{
    use RefreshDatabase;

    private PromoCodeService $service;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PromoCodeService::class);
        $this->restaurant = Restaurant::factory()->create();
    }

    // --- Chegirmasiz ---

    public function test_no_code_returns_a_zeroed_application(): void
    {
        $result = $this->service->apply(null, $this->restaurant, 10_000_00);

        $this->assertNull($result->promoCodeId);
        $this->assertSame(0, $result->discountAmount);
        $this->assertSame(0, $result->restaurantShare);
        $this->assertSame(0, $result->platformShare);
    }

    public function test_blank_code_is_treated_as_no_code(): void
    {
        $result = $this->service->apply('  ', $this->restaurant, 10_000_00);

        $this->assertNull($result->promoCodeId);
    }

    // --- Hisoblash: foizli va belgilangan summali ---

    public function test_percent_discount_is_calculated_from_subtotal(): void
    {
        $promo = PromoCode::factory()->percent(20)->create();

        $result = $this->service->apply($promo->code, $this->restaurant, 10_000_00); // 100 000 so'm

        $this->assertSame(2_000_00, $result->discountAmount); // 20 000 so'm
    }

    public function test_percent_discount_rounds_down(): void
    {
        $promo = PromoCode::factory()->percent(33)->create();

        // 999 tiyin * 33 / 100 = 329.67 -> 329 (floor)
        $result = $this->service->apply($promo->code, $this->restaurant, 999);

        $this->assertSame(329, $result->discountAmount);
    }

    public function test_fixed_discount_uses_the_configured_tiyin_amount(): void
    {
        $promo = PromoCode::factory()->fixed(15_000_00)->create();

        $result = $this->service->apply($promo->code, $this->restaurant, 50_000_00);

        $this->assertSame(15_000_00, $result->discountAmount);
    }

    public function test_discount_never_exceeds_the_subtotal(): void
    {
        $percent = PromoCode::factory()->percent(100)->create(['code' => 'FULL100']);
        $fixed = PromoCode::factory()->fixed(999_999_00)->create(['code' => 'HUGE']);

        $this->assertSame(10_000_00, $this->service->apply('FULL100', $this->restaurant, 10_000_00)->discountAmount);
        $this->assertSame(10_000_00, $this->service->apply('HUGE', $this->restaurant, 10_000_00)->discountAmount);
    }

    // --- YAXLITLASH QOIDASI: restoran ulushi floor, toq qoldiq platformaga ---

    public function test_odd_split_remainder_goes_to_the_platform(): void
    {
        // 1005 tiyin, 50%: 1005*50/100 = 502.5 -> restoran 502 (floor), platforma 503.
        $promo = PromoCode::factory()->fixed(1005)->restaurantShare(50)->create();

        $result = $this->service->apply($promo->code, $this->restaurant, 1_000_000);

        $this->assertSame(1005, $result->discountAmount);
        $this->assertSame(502, $result->restaurantShare);
        $this->assertSame(503, $result->platformShare);
        $this->assertSame($result->discountAmount, $result->restaurantShare + $result->platformShare);
    }

    public function test_split_sums_exactly_to_the_discount_for_many_amounts_and_percentages(): void
    {
        foreach ([1, 2, 3, 7, 33, 50, 66, 99, 100] as $percent) {
            foreach ([1, 2, 3, 7, 10, 999, 1_234_567] as $discount) {
                $promo = PromoCode::factory()->fixed($discount)->restaurantShare($percent)->create();

                $result = $this->service->apply($promo->code, $this->restaurant, $discount * 10);

                $this->assertSame(
                    $discount,
                    $result->restaurantShare + $result->platformShare,
                    "discount={$discount} percent={$percent}",
                );
            }
        }
    }

    public function test_zero_restaurant_share_gives_everything_to_the_platform(): void
    {
        $promo = PromoCode::factory()->fixed(1000)->restaurantShare(0)->create();

        $result = $this->service->apply($promo->code, $this->restaurant, 1_000_000);

        $this->assertSame(0, $result->restaurantShare);
        $this->assertSame(1000, $result->platformShare);
    }

    public function test_full_restaurant_share_gives_everything_to_the_restaurant(): void
    {
        $promo = PromoCode::factory()->fixed(1001)->restaurantShare(100)->create();

        $result = $this->service->apply($promo->code, $this->restaurant, 1_000_000);

        $this->assertSame(1001, $result->restaurantShare);
        $this->assertSame(0, $result->platformShare);
    }

    // --- Tekshirish: mavjud emas, o'chirilgan, muddati, restoranga bog'liq ---

    public function test_unknown_code_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->apply('YOQ-BUNDAY', $this->restaurant, 10_000_00);
    }

    public function test_code_is_matched_case_and_whitespace_insensitively(): void
    {
        PromoCode::factory()->percent(10)->create(['code' => 'OSON50']);

        $result = $this->service->apply(' oson50 ', $this->restaurant, 10_000_00);

        $this->assertSame(1_000_00, $result->discountAmount);
    }

    public function test_inactive_code_is_rejected(): void
    {
        $promo = PromoCode::factory()->inactive()->create();

        $this->expectException(ValidationException::class);

        $this->service->apply($promo->code, $this->restaurant, 10_000_00);
    }

    public function test_code_outside_its_date_range_is_rejected(): void
    {
        Carbon::setTestNow('2026-09-28 12:00:00');

        $notStarted = PromoCode::factory()->create(['code' => 'ERTAGA', 'starts_at' => now()->addDay()]);
        $ended = PromoCode::factory()->create(['code' => 'KECHA', 'ends_at' => now()->subDay()]);
        $active = PromoCode::factory()->create(['code' => 'HOZIR', 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);

        try {
            $this->service->apply('ERTAGA', $this->restaurant, 10_000_00);
            $this->fail('Boshlanmagan kod qabul qilinmasligi kerak edi.');
        } catch (ValidationException) {
        }

        try {
            $this->service->apply('KECHA', $this->restaurant, 10_000_00);
            $this->fail('Muddati o\'tgan kod qabul qilinmasligi kerak edi.');
        } catch (ValidationException) {
        }

        $this->assertSame($active->id, $this->service->apply('HOZIR', $this->restaurant, 10_000_00)->promoCodeId);

        Carbon::setTestNow();
    }

    public function test_code_restricted_to_another_restaurant_is_rejected(): void
    {
        $otherRestaurant = Restaurant::factory()->create();
        $promo = PromoCode::factory()->create(['restaurant_id' => $otherRestaurant->id]);

        $this->expectException(ValidationException::class);

        $this->service->apply($promo->code, $this->restaurant, 10_000_00);
    }

    public function test_code_without_a_restaurant_works_at_any_restaurant(): void
    {
        $promo = PromoCode::factory()->create(['restaurant_id' => null]);

        $result = $this->service->apply($promo->code, $this->restaurant, 10_000_00);

        $this->assertSame($promo->id, $result->promoCodeId);
    }

    public function test_code_restricted_to_this_restaurant_works(): void
    {
        $promo = PromoCode::factory()->create(['restaurant_id' => $this->restaurant->id]);

        $result = $this->service->apply($promo->code, $this->restaurant, 10_000_00);

        $this->assertSame($promo->id, $result->promoCodeId);
    }
}
