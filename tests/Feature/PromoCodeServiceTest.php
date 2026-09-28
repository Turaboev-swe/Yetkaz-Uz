<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PromoCodeError;
use App\Exceptions\PromoCodeException;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Ordering\PromoCodeApplication;
use App\Services\Ordering\PromoCodeService;
use App\Support\Money;
use Database\Factories\PromoCodeFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PromoCodeService — chegirmani hisoblash, platforma/restoran ulushiga bo'lish
 * (so'm darajasida aniq yaxlitlash), tekshirish (aniq sabab bilan) va
 * ishlatilish limitlari.
 */
class PromoCodeServiceTest extends TestCase
{
    use RefreshDatabase;

    private PromoCodeService $service;

    private Restaurant $restaurant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PromoCodeService::class);
        $this->restaurant = Restaurant::factory()->create();
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Shu test restoraniga biriktirilgan kod (qoida: kod faqat tanlangan restoranlarda ishlaydi). */
    private function promo(): PromoCodeFactory
    {
        return PromoCode::factory()->at($this->restaurant);
    }

    /** RefreshDatabase testni tranzaksiyaga o'raydi — applyForOrder() talabi bajariladi. */
    private function apply(?string $code, int $subtotal, ?User $user = null): PromoCodeApplication
    {
        return $this->service->applyForOrder($code, $this->restaurant, $subtotal, $user ?? $this->user);
    }

    private function assertRejected(PromoCodeError $expected, callable $call): void
    {
        try {
            $call();
            $this->fail("PromoCodeException ({$expected->value}) kutilgan edi.");
        } catch (PromoCodeException $e) {
            $this->assertSame($expected, $e->error);
            $this->assertSame($expected->message(), $e->getMessage());
        }
    }

    private function useCode(PromoCode $promo, ?User $user = null, OrderStatus $status = OrderStatus::New): Order
    {
        return Order::factory()->for($user ?? $this->user)->create([
            'promo_code_id' => $promo->id,
            'status' => $status,
        ]);
    }

    // --- Chegirmasiz ---

    public function test_no_code_returns_a_zeroed_application(): void
    {
        $result = $this->apply(null, 10_000_00);

        $this->assertNull($result->promoCodeId);
        $this->assertSame(0, $result->discountAmount);
        $this->assertSame(0, $result->restaurantAmount);
        $this->assertSame(0, $result->platformAmount);
    }

    public function test_blank_code_is_treated_as_no_code(): void
    {
        $this->assertNull($this->apply('  ', 10_000_00)->promoCodeId);
    }

    // --- Hisoblash ---

    public function test_percent_discount_is_calculated_from_subtotal(): void
    {
        $promo = $this->promo()->percent(20)->create();

        $this->assertSame(2_000_00, $this->apply($promo->code, 10_000_00)->discountAmount);
    }

    public function test_percent_discount_rounds_down_to_a_whole_som(): void
    {
        $promo = $this->promo()->percent(15)->create();

        // 12 345 so'm * 15% = 1 851,75 so'm -> 1 851 so'm.
        $this->assertSame(1_851_00, $this->apply($promo->code, 12_345_00)->discountAmount);
    }

    public function test_fixed_discount_with_a_fraction_of_a_som_rounds_down(): void
    {
        $promo = $this->promo()->fixed(5_000_50)->create(); // 5 000,50 so'm

        $this->assertSame(5_000_00, $this->apply($promo->code, 50_000_00)->discountAmount);
    }

    public function test_fixed_discount_uses_the_configured_tiyin_amount(): void
    {
        $promo = $this->promo()->fixed(15_000_00)->create();

        $this->assertSame(15_000_00, $this->apply($promo->code, 50_000_00)->discountAmount);
    }

    public function test_discount_never_exceeds_the_subtotal(): void
    {
        $this->promo()->percent(100)->create(['code' => 'FULL100']);
        $this->promo()->fixed(999_999_00)->create(['code' => 'HUGE']);

        $this->assertSame(10_000_00, $this->apply('FULL100', 10_000_00)->discountAmount);
        $this->assertSame(10_000_00, $this->apply('HUGE', 10_000_00)->discountAmount);
    }

    // --- YAXLITLASH QOIDASI: so'm darajasida, restoran floor, toq so'm platformaga ---

    public function test_odd_som_remainder_goes_to_the_platform(): void
    {
        // 15 005 so'm, 50%: restoran floor(7 502,5) = 7 502 so'm, platforma 7 503 so'm.
        $promo = $this->promo()->fixed(15_005_00)->restaurantShare(50)->create();

        $result = $this->apply($promo->code, 100_000_00);

        $this->assertSame(15_005_00, $result->discountAmount);
        $this->assertSame(7_502_00, $result->restaurantAmount);
        $this->assertSame(7_503_00, $result->platformAmount);
    }

    /**
     * Regressiya: tiyin darajasida bo'linganда 15 005 so'm -> 7 502,50 + 7 502,50
     * bo'lib, hisobot/CSV (so'mда) 7 502 + 7 502 = 15 004 ko'rsatardi.
     */
    public function test_shares_shown_in_som_add_up_to_the_discount_shown_in_som(): void
    {
        $promo = $this->promo()->fixed(15_005_00)->restaurantShare(50)->create();

        $result = $this->apply($promo->code, 100_000_00);

        $this->assertSame(
            Money::toSoms($result->discountAmount),
            Money::toSoms($result->restaurantAmount) + Money::toSoms($result->platformAmount),
        );
    }

    public function test_split_is_whole_som_and_sums_exactly_for_many_amounts_and_percentages(): void
    {
        foreach ([1, 3, 7, 33, 50, 66, 99, 100] as $percent) {
            foreach ([1, 3, 7, 101, 15_005, 1_234_567] as $discountSom) {
                $discount = $discountSom * 100;
                $promo = $this->promo()->fixed($discount)->restaurantShare($percent)->create();

                $result = $this->apply($promo->code, $discount * 10);
                $label = "discount={$discountSom} so'm percent={$percent}";

                $this->assertSame($discount, $result->restaurantAmount + $result->platformAmount, $label);
                $this->assertSame(0, $result->restaurantAmount % 100, $label);
                $this->assertSame(0, $result->platformAmount % 100, $label);
                $this->assertSame(intdiv($discountSom * $percent, 100) * 100, $result->restaurantAmount, $label);
            }
        }
    }

    public function test_zero_restaurant_share_gives_everything_to_the_platform(): void
    {
        $promo = $this->promo()->fixed(10_00)->restaurantShare(0)->create();

        $result = $this->apply($promo->code, 1_000_000);

        $this->assertSame(0, $result->restaurantAmount);
        $this->assertSame(10_00, $result->platformAmount);
    }

    public function test_full_restaurant_share_gives_everything_to_the_restaurant(): void
    {
        $promo = $this->promo()->fixed(1_001_00)->restaurantShare(100)->create();

        $result = $this->apply($promo->code, 10_000_00);

        $this->assertSame(1_001_00, $result->restaurantAmount);
        $this->assertSame(0, $result->platformAmount);
    }

    // --- Aniq sabab: topilmadi, faol emas, muddati, restoran ---

    public function test_unknown_code_is_rejected_as_not_found(): void
    {
        $this->assertRejected(PromoCodeError::NotFound, fn () => $this->apply('YOQ-BUNDAY', 10_000_00));
    }

    public function test_code_is_matched_case_and_whitespace_insensitively(): void
    {
        $this->promo()->percent(10)->create(['code' => 'OSON50']);

        $this->assertSame(1_000_00, $this->apply(' oson50 ', 10_000_00)->discountAmount);
    }

    public function test_inactive_code_is_rejected_as_inactive(): void
    {
        $promo = $this->promo()->inactive()->create();

        $this->assertRejected(PromoCodeError::Inactive, fn () => $this->apply($promo->code, 10_000_00));
    }

    public function test_expired_code_is_rejected_as_expired(): void
    {
        Carbon::setTestNow('2026-09-28 12:00:00');
        $promo = $this->promo()->create(['ends_at' => now()->subMinute()]);

        $this->assertRejected(PromoCodeError::Expired, fn () => $this->apply($promo->code, 10_000_00));
    }

    public function test_not_yet_started_code_is_rejected_as_not_started(): void
    {
        Carbon::setTestNow('2026-09-28 12:00:00');
        $promo = $this->promo()->create(['starts_at' => now()->addMinute()]);

        $this->assertRejected(PromoCodeError::NotStarted, fn () => $this->apply($promo->code, 10_000_00));
    }

    public function test_code_inside_its_date_range_is_accepted(): void
    {
        Carbon::setTestNow('2026-09-28 12:00:00');
        $promo = $this->promo()->create(['starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);

        $this->assertSame($promo->id, $this->apply($promo->code, 10_000_00)->promoCodeId);
    }

    // --- Restoranlar: faqat admin tanlaganlarida ---------------------------

    public function test_code_for_unselected_restaurant_is_rejected_as_wrong_restaurant(): void
    {
        $promo = PromoCode::factory()->at(Restaurant::factory()->create())->create();

        $this->assertRejected(PromoCodeError::WrongRestaurant, fn () => $this->apply($promo->code, 10_000_00));
        $this->assertSame('Bu promokod ushbu restoranda amal qilmaydi.', PromoCodeError::WrongRestaurant->message());
    }

    public function test_code_works_at_every_selected_restaurant_only(): void
    {
        $second = Restaurant::factory()->create();
        $notSelected = Restaurant::factory()->create();
        $promo = PromoCode::factory()->at($this->restaurant, $second)->create();

        $this->assertSame($promo->id, $this->apply($promo->code, 10_000_00)->promoCodeId);
        $this->assertSame($promo->id, $this->service->applyForOrder($promo->code, $second, 10_000_00, $this->user)->promoCodeId);
        $this->assertRejected(
            PromoCodeError::WrongRestaurant,
            fn () => $this->service->applyForOrder($promo->code, $notSelected, 10_000_00, $this->user),
        );
    }

    /** "Barcha restoranlar" varianti yo'q — restoransiz kod hech qayerda ishlamaydi. */
    public function test_code_without_restaurants_works_nowhere(): void
    {
        $promo = PromoCode::factory()->create(); // ataylab restoransiz

        $this->assertRejected(PromoCodeError::WrongRestaurant, fn () => $this->apply($promo->code, 10_000_00));
    }

    /** Restoran o'chirilsa kod "hammaga" aylanmaydi (eski FK ON DELETE SET NULL shunday qilardi). */
    public function test_deleting_the_only_restaurant_does_not_open_the_code_to_everyone(): void
    {
        $gone = Restaurant::factory()->create();
        $promo = PromoCode::factory()->at($gone)->create();

        $gone->delete();

        $this->assertSame(0, $promo->restaurants()->count());
        $this->assertRejected(PromoCodeError::WrongRestaurant, fn () => $this->apply($promo->code, 10_000_00));
    }

    // --- Minimal summa: faqat taomlar (subtotal), chegarada ishlaydi ---------

    public function test_minimum_is_met_at_exactly_the_minimum(): void
    {
        $promo = $this->promo()->minOrder(50_000_00)->create();

        $this->assertSame($promo->id, $this->apply($promo->code, 50_000_00)->promoCodeId);
    }

    public function test_one_som_below_the_minimum_is_rejected_with_the_amount(): void
    {
        $promo = $this->promo()->minOrder(50_000_00)->create();

        try {
            $this->apply($promo->code, 49_999_00);
            $this->fail('Minimaldan 1 so\'m kam summa qabul qilinmasligi kerak edi.');
        } catch (PromoCodeException $e) {
            $this->assertSame(PromoCodeError::BelowMinimum, $e->error);
            $this->assertSame("Promokod 50 000 so'm va undan ortiq buyurtmada ishlaydi.", $e->getMessage());
        }
    }

    /** Tuzatib bo'lmaydigan sabab (limit) minimal summadan oldin aytiladi. */
    public function test_limit_is_reported_before_the_minimum(): void
    {
        $promo = $this->promo()->minOrder(50_000_00)->create(['per_user_limit' => 1]);
        $this->useCode($promo);

        $this->assertRejected(PromoCodeError::UserLimitReached, fn () => $this->apply($promo->code, 10_000_00));
    }

    // --- Bir marta: standart limit 1 ----------------------------------------

    public function test_default_per_user_limit_is_one_and_a_second_use_is_rejected(): void
    {
        $promo = PromoCode::query()->create(['code' => 'BIRMARTA', 'discount_type' => 'percent', 'discount_value' => 10]);
        $promo->restaurants()->attach($this->restaurant);

        $this->assertSame(1, $promo->fresh()->per_user_limit); // baza standarti

        $this->assertSame($promo->id, $this->apply('BIRMARTA', 10_000_00)->promoCodeId);
        $this->useCode($promo);
        $this->assertRejected(PromoCodeError::UserLimitReached, fn () => $this->apply('BIRMARTA', 10_000_00));

        // Bekor qilingan birinchi buyurtma hisoblanmaydi — yana ishlaydi.
        $promo->orders()->update(['status' => OrderStatus::Cancelled->value]);
        $this->assertSame($promo->id, $this->apply('BIRMARTA', 10_000_00)->promoCodeId);
    }

    // --- Limitlar: faqat bekor qilinmagan buyurtmalar sanaladi ---

    public function test_total_usage_limit_blocks_once_reached(): void
    {
        $promo = $this->promo()->create(['total_usage_limit' => 2]);
        $this->useCode($promo, User::factory()->create());
        $this->assertSame($promo->id, $this->apply($promo->code, 10_000_00)->promoCodeId); // 1 ta ishlatilgan — joy bor

        $this->useCode($promo, User::factory()->create());

        $this->assertRejected(PromoCodeError::UsageLimitReached, fn () => $this->apply($promo->code, 10_000_00));
    }

    public function test_per_user_limit_blocks_only_that_user(): void
    {
        $promo = $this->promo()->create(['per_user_limit' => 1]);
        $this->useCode($promo);

        $this->assertRejected(PromoCodeError::UserLimitReached, fn () => $this->apply($promo->code, 10_000_00));

        // Boshqa mijoz uchun — joy bor.
        $this->assertSame($promo->id, $this->apply($promo->code, 10_000_00, User::factory()->create())->promoCodeId);
    }

    public function test_cancelled_orders_do_not_count_toward_either_limit(): void
    {
        $promo = $this->promo()->create(['per_user_limit' => 1, 'total_usage_limit' => 1]);
        $this->useCode($promo, status: OrderStatus::Cancelled);

        $this->assertSame($promo->id, $this->apply($promo->code, 10_000_00)->promoCodeId);
    }

    public function test_delivered_and_in_progress_orders_count_as_usage(): void
    {
        $promo = $this->promo()->create(['total_usage_limit' => 2]);
        $this->useCode($promo, User::factory()->create(), OrderStatus::Delivered);
        $this->useCode($promo, User::factory()->create(), OrderStatus::Preparing);

        $this->assertRejected(PromoCodeError::UsageLimitReached, fn () => $this->apply($promo->code, 10_000_00));
    }

    public function test_null_limits_mean_unlimited(): void
    {
        $promo = $this->promo()->create(['per_user_limit' => null, 'total_usage_limit' => null]);
        foreach (range(1, 5) as $_) {
            $this->useCode($promo);
        }

        $this->assertSame($promo->id, $this->apply($promo->code, 10_000_00)->promoCodeId);
    }

    // --- Qulf (poyga holatiga qarshi) ---

    public function test_apply_for_order_locks_the_promo_code_row(): void
    {
        $promo = $this->promo()->create();
        $sql = $this->captureSql(fn () => $this->apply($promo->code, 10_000_00));

        $this->assertTrue(
            collect($sql)->contains(fn (string $q) => str_contains($q, 'promo_codes') && str_contains($q, 'for update')),
            'promo_codes SELECT ... FOR UPDATE bajarilmadi',
        );
    }

    public function test_quote_does_not_lock_but_gives_the_same_result(): void
    {
        $promo = $this->promo()->fixed(15_005_00)->restaurantShare(50)->create();

        $sql = $this->captureSql(fn () => $this->service->quote($promo->code, $this->restaurant, 100_000_00, $this->user));
        $quoted = $this->service->quote($promo->code, $this->restaurant, 100_000_00, $this->user);
        $applied = $this->apply($promo->code, 100_000_00);

        $this->assertFalse(collect($sql)->contains(fn (string $q) => str_contains($q, 'for update')));
        $this->assertEquals($applied, $quoted);
    }

    public function test_quote_reports_the_same_specific_reasons(): void
    {
        $promo = $this->promo()->create(['per_user_limit' => 1]);
        $this->useCode($promo);

        $this->assertRejected(
            PromoCodeError::UserLimitReached,
            fn () => $this->service->quote($promo->code, $this->restaurant, 10_000_00, $this->user),
        );
    }

    /** @return list<string> */
    private function captureSql(callable $call): array
    {
        $sql = [];
        DB::listen(function ($query) use (&$sql) {
            $sql[] = strtolower($query->sql);
        });

        $call();

        return $sql;
    }
}
