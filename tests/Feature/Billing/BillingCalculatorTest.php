<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingPlan;
use App\Enums\OrderStatus;
use App\Models\District;
use App\Models\Order;
use App\Models\Restaurant;
use App\Services\Billing\BillingCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Billing\Concerns\RecordsTariffs;
use Tests\TestCase;

/**
 * Obuna hisobi: percent (5%), fixed, bekor qilingan va yetkazish haqi kirmasligi,
 * Toshkent oy chegarasi, promo chegirma.
 */
class BillingCalculatorTest extends TestCase
{
    use RecordsTariffs;
    use RefreshDatabase;

    private BillingCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = app(BillingCalculator::class);
    }

    private function restaurant(array $overrides = []): Restaurant
    {
        $restaurant = Restaurant::factory()->for(District::factory())->create(array_replace([
            'billing_plan' => BillingPlan::Percent,
            'commission_rate_bp' => 500,
            'monthly_fee' => 10_000_000,
            'billing_started_at' => null,
            'is_test' => false,
        ], $overrides));

        return $this->recordTariff($restaurant);
    }

    /** Toshkent vaqti -> bazaga yoziladigan UTC. */
    private static function tashkent(string $local): Carbon
    {
        return Carbon::parse($local, 'Asia/Tashkent')->utc();
    }

    private function delivered(Restaurant $r, string $tashkentTime, int $subtotal, array $extra = []): Order
    {
        return Order::factory()->forRestaurant($r)->create(array_replace([
            'status' => OrderStatus::Delivered,
            'delivered_at' => self::tashkent($tashkentTime),
            'subtotal' => $subtotal,
            'delivery_fee' => 0,
            'discount_amount' => 0,
            'discount_restaurant_amount' => 0,
            'discount_platform_amount' => 0,
            'total' => $subtotal,
        ], $extra));
    }

    // --- Tarif hisobi -----------------------------------------------------------------

    public function test_percent_plan_is_five_percent_of_delivered_food_total(): void
    {
        $r = $this->restaurant();
        $this->delivered($r, '2026-11-05 12:00', 2_000_000);
        $this->delivered($r, '2026-11-20 19:30', 3_000_000);

        $result = $this->calc->calculate($r, '2026-11');

        $this->assertSame(2, $result->ordersCount);
        $this->assertSame(5_000_000, $result->ordersTotal);
        $this->assertSame(250_000, $result->amountDue); // 5% = 2 500 so'm
        $this->assertTrue($result->billable);
    }

    public function test_custom_rate_in_basis_points(): void
    {
        $r = $this->restaurant(['commission_rate_bp' => 300]);
        $this->delivered($r, '2026-11-05 12:00', 10_000_000);

        $this->assertSame(300_000, $this->calc->calculate($r, '2026-11')->amountDue); // 3%
    }

    public function test_fixed_plan_is_the_monthly_fee_regardless_of_orders(): void
    {
        $r = $this->restaurant(['billing_plan' => BillingPlan::Fixed]);

        $none = $this->calc->calculate($r, '2026-11');
        $this->delivered($r, '2026-11-05 12:00', 90_000_000);
        $many = $this->calc->calculate($r, '2026-11');

        $this->assertSame(10_000_000, $none->amountDue);   // 100 000 so'm
        $this->assertSame(10_000_000, $many->amountDue);
        $this->assertSame(1, $many->ordersCount);          // ma'lumot uchun hisoblanadi
    }

    public function test_percent_with_no_orders_is_zero(): void
    {
        $result = $this->calc->calculate($this->restaurant(), '2026-11');

        $this->assertSame([0, 0, 0], [$result->ordersCount, $result->ordersTotal, $result->amountDue]);
    }

    // --- Nima kirmaydi -------------------------------------------------------------------

    public function test_cancelled_and_unfinished_orders_are_excluded(): void
    {
        $r = $this->restaurant();
        $this->delivered($r, '2026-11-05 12:00', 4_000_000);

        foreach ([OrderStatus::Cancelled, OrderStatus::New, OrderStatus::Accepted, OrderStatus::Preparing, OrderStatus::OnTheWay] as $status) {
            Order::factory()->forRestaurant($r)->create([
                'status' => $status,
                'subtotal' => 9_000_000,
                'total' => 9_000_000,
                'delivery_fee' => 0,
                'delivered_at' => $status === OrderStatus::Cancelled ? null : self::tashkent('2026-11-06 12:00'),
                'cancelled_at' => $status === OrderStatus::Cancelled ? self::tashkent('2026-11-06 12:00') : null,
            ]);
        }

        $result = $this->calc->calculate($r, '2026-11');

        $this->assertSame(1, $result->ordersCount);
        $this->assertSame(4_000_000, $result->ordersTotal);
        $this->assertSame(200_000, $result->amountDue);
    }

    public function test_delivery_fee_is_not_part_of_the_base(): void
    {
        $r = $this->restaurant();
        $this->delivered($r, '2026-11-05 12:00', 4_000_000, ['delivery_fee' => 1_500_000, 'total' => 5_500_000]);

        $result = $this->calc->calculate($r, '2026-11');

        $this->assertSame(4_000_000, $result->ordersTotal);
        $this->assertSame(200_000, $result->amountDue);
    }

    public function test_other_restaurants_orders_are_not_counted(): void
    {
        $r = $this->restaurant();
        $this->delivered($r, '2026-11-05 12:00', 1_000_000);
        $this->delivered($this->restaurant(), '2026-11-05 12:00', 8_000_000);

        $this->assertSame(1_000_000, $this->calc->calculate($r, '2026-11')->ordersTotal);
    }

    // --- Promo chegirma ------------------------------------------------------------------

    public function test_promo_split_fifty_fifty_takes_only_the_restaurants_share_off(): void
    {
        $r = $this->restaurant();
        // Taomlar 100 000 so'm, promo 20 000 so'm, 50/50: restoran 10 000, platforma 10 000.
        $this->delivered($r, '2026-11-05 12:00', 10_000_000, [
            'discount_amount' => 2_000_000,
            'discount_restaurant_amount' => 1_000_000,
            'discount_platform_amount' => 1_000_000,
            'total' => 8_000_000,
        ]);

        $result = $this->calc->calculate($r, '2026-11');

        $this->assertSame(9_000_000, $result->ordersTotal);  // 100 000 − 10 000 (restoran ulushi)
        $this->assertSame(450_000, $result->amountDue);       // 5% of 90 000 so'm
    }

    public function test_promo_fully_paid_by_platform_does_not_reduce_the_base_and_fully_by_restaurant_does(): void
    {
        $r = $this->restaurant();
        $this->delivered($r, '2026-11-05 12:00', 10_000_000, [
            'discount_amount' => 2_000_000, 'discount_restaurant_amount' => 0, 'discount_platform_amount' => 2_000_000, 'total' => 8_000_000,
        ]);
        $this->delivered($r, '2026-11-06 12:00', 10_000_000, [
            'discount_amount' => 2_000_000, 'discount_restaurant_amount' => 2_000_000, 'discount_platform_amount' => 0, 'total' => 8_000_000,
        ]);

        $this->assertSame(10_000_000 + 8_000_000, $this->calc->calculate($r, '2026-11')->ordersTotal);
    }

    // --- Oy chegarasi (Asia/Tashkent) ---------------------------------------------------------

    public function test_month_boundary_follows_tashkent_time_october_to_november(): void
    {
        $r = $this->restaurant();
        // 31-oktyabr 23:30 Toshkent = 18:30 UTC (oktyabr); 1-noyabr 00:30 Toshkent = 31-okt 19:30 UTC (noyabr).
        $this->delivered($r, '2026-10-31 23:30', 1_000_000);
        $this->delivered($r, '2026-11-01 00:30', 2_000_000);

        $october = $this->calc->calculate($r, '2026-10');
        $november = $this->calc->calculate($r, '2026-11');

        $this->assertSame([1, 1_000_000], [$october->ordersCount, $october->ordersTotal]);
        $this->assertSame([1, 2_000_000], [$november->ordersCount, $november->ordersTotal]);
        $this->assertSame(100_000, $november->amountDue);
        $this->assertFalse($october->billable, 'hisob-kitob 2026-11-01 dan boshlanadi');
        $this->assertSame(0, $october->amountDue);
    }

    public function test_month_boundary_exact_edges_and_december(): void
    {
        $r = $this->restaurant();
        $this->delivered($r, '2026-11-01 00:00:00', 1_000_000);  // noyabrning birinchi soniyasi
        $this->delivered($r, '2026-11-30 23:59:59', 2_000_000);  // noyabrning oxirgi soniyasi
        $this->delivered($r, '2026-12-01 00:00:00', 4_000_000);  // dekabr

        $this->assertSame(3_000_000, $this->calc->calculate($r, '2026-11')->ordersTotal);
        $this->assertSame(4_000_000, $this->calc->calculate($r, '2026-12')->ordersTotal);
    }

    // --- Boshlanish sanasi va hisoblanmaydiganlar -----------------------------------------------

    public function test_billing_started_at_excludes_earlier_orders_for_percent(): void
    {
        $percent = $this->restaurant(['billing_started_at' => '2026-11-15']);
        $this->delivered($percent, '2026-11-14 23:59', 5_000_000); // boshlanishdan oldin
        $this->delivered($percent, '2026-11-15 00:01', 2_000_000);

        $result = $this->calc->calculate($percent, '2026-11');

        $this->assertSame(2_000_000, $result->ordersTotal);
        $this->assertSame(100_000, $result->amountDue, 'percent proratsiya qilinmaydi — faqat sanadan keyingi buyurtmalar');
    }

    // --- Fixed tarif: birinchi oy proratsiyasi ----------------------------------------------------

    public function test_fixed_plan_is_prorated_in_the_first_month_when_started_mid_month(): void
    {
        // 16-noyabrdan: 16..30 = 15 kun, 15/30 -> 100 000 so'mning yarmi
        $r = $this->restaurant(['billing_plan' => BillingPlan::Fixed, 'billing_started_at' => '2026-11-16']);

        $this->assertSame([15, 30], BillingCalculator::activeDays($r->billing_started_at, '2026-11'));
        $this->assertSame(5_000_000, $this->calc->calculate($r, '2026-11')->amountDue);
    }

    public function test_fixed_plan_following_months_are_full(): void
    {
        $r = $this->restaurant(['billing_plan' => BillingPlan::Fixed, 'billing_started_at' => '2026-11-16']);

        $this->assertSame(10_000_000, $this->calc->calculate($r, '2026-12')->amountDue);
        $this->assertSame([31, 31], BillingCalculator::activeDays($r->billing_started_at, '2026-12'));
    }

    public function test_fixed_plan_started_on_the_first_or_without_date_is_not_prorated(): void
    {
        $first = $this->restaurant(['billing_plan' => BillingPlan::Fixed, 'billing_started_at' => '2026-11-01']);
        $none = $this->restaurant(['billing_plan' => BillingPlan::Fixed, 'billing_started_at' => null]);

        $this->assertSame(10_000_000, $this->calc->calculate($first, '2026-11')->amountDue);
        $this->assertSame(10_000_000, $this->calc->calculate($none, '2026-11')->amountDue);
    }

    public function test_fixed_plan_started_on_the_last_day_pays_one_day(): void
    {
        $r = $this->restaurant(['billing_plan' => BillingPlan::Fixed, 'billing_started_at' => '2026-11-30']);

        $this->assertSame([1, 30], BillingCalculator::activeDays($r->billing_started_at, '2026-11'));
        // 100 000 / 30 = 3 333,33 so'm -> 3 333 so'm
        $this->assertSame(333_300, $this->calc->calculate($r, '2026-11')->amountDue);
    }

    public function test_proration_is_rounded_to_whole_som_half_up(): void
    {
        // 16/30 -> 53 333,33 so'm -> 53 333; 17/31 (dekabr, 15-kun) -> 54 838,71 -> 54 839
        $this->assertSame(5_333_300, BillingCalculator::amountDue(BillingPlan::Fixed, 0, 10_000_000, 0, 16, 30));
        $this->assertSame(5_483_900, BillingCalculator::amountDue(BillingPlan::Fixed, 0, 10_000_000, 0, 17, 31));
        $this->assertSame(10_000_000, BillingCalculator::amountDue(BillingPlan::Fixed, 0, 10_000_000, 0, 30, 30));
        $this->assertSame(10_000_000, BillingCalculator::amountDue(BillingPlan::Fixed, 0, 10_000_000, 0), 'kunlar berilmasa to‘liq');
    }

    public function test_period_before_billing_started_month_is_not_billable(): void
    {
        $r = $this->restaurant(['billing_started_at' => '2026-12-10']);

        $this->assertFalse($this->calc->isBillable($r, '2026-11'));
        $this->assertTrue($this->calc->isBillable($r, '2026-12'));
    }

    public function test_restaurant_without_plan_and_test_restaurants_are_not_billable(): void
    {
        $this->assertFalse($this->calc->isBillable($this->restaurant(['billing_plan' => null]), '2026-11'));
        $this->assertFalse($this->calc->isBillable($this->restaurant(['is_test' => true]), '2026-11'));
        $this->assertSame(0, $this->calc->calculate($this->restaurant(['billing_plan' => null]), '2026-11')->amountDue);
    }

    // --- Yaxlitlash va oyna ------------------------------------------------------------------------

    public function test_percent_is_rounded_to_whole_som_so_that_payments_in_som_can_settle_it(): void
    {
        // 33 333,30 so'm × 5% = 1 666,665 so'm -> 1 667 so'm
        $this->assertSame(166_700, BillingCalculator::amountDue(BillingPlan::Percent, 500, 0, 3_333_330));
        $this->assertSame(166_700, BillingCalculator::amountDue(BillingPlan::Percent, 500, 0, 3_333_000)); // 1 666,50 -> yarim yuqoriga
        $this->assertSame(166_600, BillingCalculator::amountDue(BillingPlan::Percent, 500, 0, 3_332_900)); // 1 666,45 -> pastga
    }

    public function test_orders_query_matches_the_calculated_numbers(): void
    {
        $r = $this->restaurant(['billing_started_at' => '2026-11-10']);
        $this->delivered($r, '2026-11-09 12:00', 1_000_000);
        $this->delivered($r, '2026-11-10 12:00', 2_000_000);
        $this->delivered($r, '2026-11-25 12:00', 3_000_000);
        $this->delivered($r, '2026-12-01 00:10', 9_000_000);

        $result = $this->calc->calculate($r, '2026-11');
        $query = $this->calc->ordersQuery($r->id, '2026-11', $r->billing_started_at);

        $this->assertSame($result->ordersCount, $query->count());
        $this->assertSame($result->ordersTotal, (int) $query->sum('subtotal'));
        $this->assertSame(2, $result->ordersCount);
    }

    public function test_month_labels_are_uzbek(): void
    {
        $this->assertSame('Noyabr 2026', BillingCalculator::monthLabel('2026-11'));
        $this->assertSame('Yanvar 2027', BillingCalculator::monthLabel('2027-01-15'));
    }
}
