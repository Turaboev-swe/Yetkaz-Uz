<?php

namespace Tests\Feature;

use App\Models\PromoCode;
use App\Models\Restaurant;
use App\Services\Ordering\PromoCodeService;
use App\Support\Money;
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

    public function test_percent_discount_rounds_down_to_a_whole_som(): void
    {
        $promo = PromoCode::factory()->percent(15)->create();

        // 12 345 so'm * 15% = 1 851,75 so'm -> 1 851 so'm (kasrli so'm total'ni
        // kasrli qilib, Mini App va panellarда turli summa ko'rsatardi).
        $result = $this->service->apply($promo->code, $this->restaurant, 12_345_00);

        $this->assertSame(1_851_00, $result->discountAmount);
    }

    public function test_fixed_discount_with_a_fraction_of_a_som_rounds_down(): void
    {
        $promo = PromoCode::factory()->fixed(5_000_50)->create(); // 5 000,50 so'm

        $result = $this->service->apply($promo->code, $this->restaurant, 50_000_00);

        $this->assertSame(5_000_00, $result->discountAmount);
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

    // --- YAXLITLASH QOIDASI: so'm darajasida, restoran floor, toq so'm platformaga ---

    public function test_odd_som_remainder_goes_to_the_platform(): void
    {
        // 15 005 so'm, 50%: restoran floor(7 502,5) = 7 502 so'm, platforma 7 503 so'm.
        $promo = PromoCode::factory()->fixed(15_005_00)->restaurantShare(50)->create();

        $result = $this->service->apply($promo->code, $this->restaurant, 100_000_00);

        $this->assertSame(15_005_00, $result->discountAmount);
        $this->assertSame(7_502_00, $result->restaurantShare);
        $this->assertSame(7_503_00, $result->platformShare);
    }

    /**
     * Regressiya: tiyin darajasida bo'linganда 15 005 so'm -> 7 502,50 + 7 502,50
     * bo'lib, hisobot/CSV (so'mда) 7 502 + 7 502 = 15 004 ko'rsatardi.
     */
    public function test_shares_shown_in_som_add_up_to_the_discount_shown_in_som(): void
    {
        $promo = PromoCode::factory()->fixed(15_005_00)->restaurantShare(50)->create();

        $result = $this->service->apply($promo->code, $this->restaurant, 100_000_00);

        $this->assertSame(
            Money::toSoms($result->discountAmount),
            Money::toSoms($result->restaurantShare) + Money::toSoms($result->platformShare),
        );
    }

    public function test_split_is_whole_som_and_sums_exactly_for_many_amounts_and_percentages(): void
    {
        foreach ([1, 3, 7, 33, 50, 66, 99, 100] as $percent) {
            foreach ([1, 3, 7, 101, 15_005, 1_234_567] as $discountSom) {
                $discount = $discountSom * 100;
                $promo = PromoCode::factory()->fixed($discount)->restaurantShare($percent)->create();

                $result = $this->service->apply($promo->code, $this->restaurant, $discount * 10);
                $label = "discount={$discountSom} so'm percent={$percent}";

                $this->assertSame($discount, $result->restaurantShare + $result->platformShare, $label);
                $this->assertSame(0, $result->restaurantShare % 100, $label);
                $this->assertSame(0, $result->platformShare % 100, $label);
                $this->assertSame(intdiv($discountSom * $percent, 100) * 100, $result->restaurantShare, $label);
            }
        }
    }

    public function test_zero_restaurant_share_gives_everything_to_the_platform(): void
    {
        $promo = PromoCode::factory()->fixed(10_00)->restaurantShare(0)->create();

        $result = $this->service->apply($promo->code, $this->restaurant, 1_000_000);

        $this->assertSame(0, $result->restaurantShare);
        $this->assertSame(10_00, $result->platformShare);
    }

    public function test_full_restaurant_share_gives_everything_to_the_restaurant(): void
    {
        $promo = PromoCode::factory()->fixed(1_001_00)->restaurantShare(100)->create();

        $result = $this->service->apply($promo->code, $this->restaurant, 10_000_00);

        $this->assertSame(1_001_00, $result->restaurantShare);
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
