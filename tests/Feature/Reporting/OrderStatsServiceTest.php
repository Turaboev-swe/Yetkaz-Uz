<?php

namespace Tests\Feature\Reporting;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\PromoCode;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Reporting\OrderStatsService;
use App\Services\Reporting\ReportPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OrderStatsServiceTest extends TestCase
{
    use RefreshDatabase;

    private OrderStatsService $stats;

    private Restaurant $a;

    private Restaurant $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stats = app(OrderStatsService::class);
        $this->a = Restaurant::factory()->create(['name' => 'Alfa']);
        $this->b = Restaurant::factory()->create(['name' => 'Beta']);
    }

    private function period(): ReportPeriod
    {
        return ReportPeriod::custom('2026-09-01', '2026-09-30');
    }

    public function test_summary_counts_and_revenue_only_delivered(): void
    {
        $u1 = User::factory()->create();
        $u2 = User::factory()->create();

        Order::factory()->forRestaurant($this->a)->for($u1)->placedAt('2026-09-05 10:00')
            ->delivered('2026-09-05 11:00')->create(['total' => 50_000_00]);
        Order::factory()->forRestaurant($this->a)->for($u2)->placedAt('2026-09-06 10:00')
            ->delivered('2026-09-06 11:00')->create(['total' => 30_000_00]);
        Order::factory()->forRestaurant($this->a)->for($u1)->placedAt('2026-09-07 10:00')
            ->cancelled()->create(['total' => 99_000_00]);
        Order::factory()->forRestaurant($this->a)->for($u1)->placedAt('2026-09-08 10:00')
            ->create(['total' => 12_000_00, 'status' => OrderStatus::New]);
        // Oraliqdan tashqarida — hisobga olinmaydi.
        Order::factory()->forRestaurant($this->a)->placedAt('2026-08-20 10:00')->delivered('2026-08-20 11:00')->create();

        $s = $this->stats->summary($this->period(), $this->a->id);

        $this->assertSame(4, $s['orders']);
        $this->assertSame(2, $s['delivered']);
        $this->assertSame(1, $s['cancelled']);
        $this->assertSame(80_000_00, $s['revenue_tiyin']); // faqat yetkazilgan 50k + 30k
        $this->assertSame(40_000_00, $s['avg_check_tiyin']);
        $this->assertSame(2, $s['customers']);
    }

    public function test_top_restaurants_ranked_by_order_count(): void
    {
        Order::factory()->count(3)->forRestaurant($this->a)->placedAt('2026-09-10 10:00')->create();
        Order::factory()->count(5)->forRestaurant($this->b)->placedAt('2026-09-10 10:00')->create();

        $top = $this->stats->topRestaurants($this->period());

        $this->assertSame('Beta', $top[0]['name']);
        $this->assertSame(5, $top[0]['orders']);
        $this->assertSame('Alfa', $top[1]['name']);
        $this->assertSame(3, $top[1]['orders']);
    }

    public function test_kitchen_performance_computes_timings_from_status_history(): void
    {
        $order = Order::factory()->forRestaurant($this->a)->placedAt('2026-09-12 10:00:00')
            ->create([
                'status' => OrderStatus::Delivered,
                'dispatched_at' => Carbon::parse('2026-09-12 10:25:00'),
                'delivered_at' => Carbon::parse('2026-09-12 10:50:00'),
            ]);

        OrderStatusHistory::create([
            'order_id' => $order->id,
            'status' => OrderStatus::Accepted->value,
            'changed_by' => 'kitchen:1',
            'changed_at' => Carbon::parse('2026-09-12 10:05:00'), // qabul: 5 daqiqa
        ]);

        $perf = $this->stats->kitchenPerformance($this->period(), $this->a->id);

        $this->assertCount(1, $perf);
        $this->assertSame(5.0, $perf[0]['avg_accept_min']);        // 10:00 -> 10:05
        $this->assertSame(20.0, $perf[0]['avg_prep_min']);         // 10:05 -> 10:25 (dispatched)
        $this->assertSame(50.0, $perf[0]['avg_fulfilment_min']);   // 10:00 -> 10:50
    }

    public function test_top_products_aggregates_from_items_json(): void
    {
        Order::factory()->forRestaurant($this->a)->placedAt('2026-09-15 10:00')->delivered()->items([
            ['product_id' => 25, 'name' => 'Grill burger', 'price' => 34_000_00, 'qty' => 2],
            ['product_id' => 28, 'name' => 'Fri kartoshka', 'price' => 13_000_00, 'qty' => 1],
        ])->create();
        Order::factory()->forRestaurant($this->a)->placedAt('2026-09-16 10:00')->delivered()->items([
            ['product_id' => 25, 'name' => 'Grill burger', 'price' => 34_000_00, 'qty' => 3],
        ])->create();

        $top = $this->stats->topProducts($this->period(), $this->a->id);

        $this->assertSame(25, $top[0]['product_id']);
        $this->assertSame(5, $top[0]['qty']);
        $this->assertSame(5 * 34_000_00, $top[0]['revenue_tiyin']);
        $this->assertSame('Fri kartoshka', $top[1]['name']);
        $this->assertSame(1, $top[1]['qty']);
    }

    /** REPORT-2: bekor qilingan / yakunlanmagan buyurtma "sotilgan" hisoblanmaydi. */
    public function test_top_products_excludes_cancelled_and_unfinished_orders(): void
    {
        Order::factory()->forRestaurant($this->a)->placedAt('2026-09-15 10:00')->delivered()->items([
            ['product_id' => 25, 'name' => 'Grill burger', 'price' => 34_000_00, 'qty' => 2],
        ])->create();
        Order::factory()->forRestaurant($this->a)->placedAt('2026-09-16 10:00')->cancelled()->items([
            ['product_id' => 25, 'name' => 'Grill burger', 'price' => 34_000_00, 'qty' => 10],
        ])->create();
        Order::factory()->forRestaurant($this->a)->placedAt('2026-09-17 10:00')->items([
            ['product_id' => 25, 'name' => 'Grill burger', 'price' => 34_000_00, 'qty' => 7],
        ])->create(['status' => OrderStatus::New]);

        $top = $this->stats->topProducts($this->period(), $this->a->id);

        $this->assertCount(1, $top);
        $this->assertSame(2, $top[0]['qty']); // faqat yetkazilgan buyurtmadagi 2 dona
        $this->assertSame(2 * 34_000_00, $top[0]['revenue_tiyin']);
    }

    public function test_orders_per_day_fills_empty_days_with_zero(): void
    {
        Order::factory()->forRestaurant($this->a)->placedAt('2026-09-03 12:00')->create();
        Order::factory()->count(2)->forRestaurant($this->a)->placedAt('2026-09-05 12:00')->create();

        $series = $this->stats->ordersPerDay(ReportPeriod::custom('2026-09-03', '2026-09-06'), $this->a->id);

        $this->assertSame(
            [['2026-09-03', 1], ['2026-09-04', 0], ['2026-09-05', 2], ['2026-09-06', 0]],
            $series->map(fn ($r) => [$r['date'], $r['orders']])->all(),
        );
    }

    /**
     * REPORT-1 — integratsiya darajasida: "Bugun" (Toshkent) presetida
     * ertalabki soatlarda (00:00-04:59) yaratilgan buyurtma to'g'ri
     * hisoblanadi, kechagi/ertangi (Toshkent) buyurtmalar kirmaydi.
     */
    public function test_today_preset_counts_revenue_within_the_tashkent_calendar_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-21 12:00:00', 'Asia/Tashkent'));

        // Toshkent 21-sentabr 02:00 (BUGUN) — eski (UTC) mantiq bo'yicha bu UTC
        // 20-sentabr 21:00 bo'lganidan "kecha"ga tushib qolardi.
        Order::factory()->forRestaurant($this->a)->delivered()
            ->placedAt(Carbon::parse('2026-09-21 02:00:00', 'Asia/Tashkent')->utc())
            ->create(['total' => 10_000_00]);

        // Toshkent 22-sentabr 00:30 (ERTAGA) — eski mantiq bo'yicha UTC 21-sentabr
        // 19:30 bo'lib, "bugun"ga noto'g'ri kirib qolardi.
        Order::factory()->forRestaurant($this->a)->delivered()
            ->placedAt(Carbon::parse('2026-09-22 00:30:00', 'Asia/Tashkent')->utc())
            ->create(['total' => 20_000_00]);

        // Toshkent 20-sentabr 23:30 (KECHA) — aniq "bugun" emas.
        Order::factory()->forRestaurant($this->a)->delivered()
            ->placedAt(Carbon::parse('2026-09-20 23:30:00', 'Asia/Tashkent')->utc())
            ->create(['total' => 30_000_00]);

        $s = $this->stats->summary(ReportPeriod::preset('today'), $this->a->id);

        $this->assertSame(1, $s['orders']);
        $this->assertSame(10_000_00, $s['revenue_tiyin']);

        Carbon::setTestNow();
    }

    /** Regressiya: boshqa presetlar (custom bilan bir xil natija berishi kerak bo'lgan holatlar) xato bermaydi. */
    public function test_all_time_and_other_presets_still_work(): void
    {
        Order::factory()->forRestaurant($this->a)->placedAt('2026-09-10 10:00')->delivered()->create(['total' => 5_000_00]);

        foreach (['today', 'week', 'month', 'quarter', 'all'] as $key) {
            $s = $this->stats->summary(ReportPeriod::preset($key), $this->a->id);
            $this->assertIsInt($s['revenue_tiyin']);
        }

        $s = $this->stats->summary(ReportPeriod::preset('all'), $this->a->id);
        $this->assertSame(5_000_00, $s['revenue_tiyin']);
    }

    public function test_restaurant_scope_excludes_other_restaurants(): void
    {
        Order::factory()->count(2)->forRestaurant($this->a)->placedAt('2026-09-10 10:00')->create();
        Order::factory()->count(9)->forRestaurant($this->b)->placedAt('2026-09-10 10:00')->create();

        $this->assertSame(2, $this->stats->summary($this->period(), $this->a->id)['orders']);
        $this->assertSame(9, $this->stats->summary($this->period(), $this->b->id)['orders']);
        $this->assertSame(11, $this->stats->summary($this->period(), null)['orders']);
    }

    // --- Daromad: mijoz to'lagan + platforma qoplaydigan chegirma ----------

    /**
     * 100 000 so'm (taom+yetkazish), 20 000 so'm chegirma -> mijoz 80 000 to'laydi.
     * Daromad = 80 000 + platforma qismi: ulush 0% -> 100 000, 50% -> 90 000, 100% -> 80 000.
     */
    public function test_revenue_is_what_the_customer_paid_plus_the_platform_covered_part(): void
    {
        foreach ([0 => 100_000_00, 50 => 90_000_00, 100 => 80_000_00] as $restaurantPercent => $expectedRevenue) {
            $restaurant = Restaurant::factory()->create();
            $restaurantAmount = intdiv(20_000_00 * $restaurantPercent, 100);
            Order::factory()->forRestaurant($restaurant)->placedAt('2026-09-05 10:00')->delivered('2026-09-05 11:00')
                ->discounted(20_000_00, $restaurantAmount)
                ->create(['total' => 80_000_00]);

            $s = $this->stats->summary($this->period(), $restaurant->id);

            $this->assertSame($expectedRevenue, $s['revenue_tiyin'], "ulush {$restaurantPercent}%");
            $this->assertSame($expectedRevenue, $s['avg_check_tiyin'], "ulush {$restaurantPercent}%");
        }
    }

    public function test_top_restaurants_revenue_uses_the_same_definition(): void
    {
        Order::factory()->forRestaurant($this->a)->placedAt('2026-09-05 10:00')->delivered('2026-09-05 11:00')
            ->discounted(20_000_00, 5_000_00) // restoran 5 000, platforma 15 000
            ->create(['total' => 80_000_00]);

        $top = $this->stats->topRestaurants($this->period());

        $this->assertSame(95_000_00, $top[0]['revenue_tiyin']); // 80 000 + 15 000
    }

    public function test_revenue_ignores_discounts_of_undelivered_orders(): void
    {
        Order::factory()->forRestaurant($this->a)->placedAt('2026-09-05 10:00')->cancelled()
            ->discounted(20_000_00)->create(['total' => 80_000_00]);

        $this->assertSame(0, $this->stats->summary($this->period(), $this->a->id)['revenue_tiyin']);
    }

    // --- platformDiscounts — har restoranga platforma qancha qoplashi kerak ---

    public function test_platform_discounts_aggregate_per_restaurant_sorted_by_platform_amount(): void
    {
        $promo = PromoCode::factory()->create();

        Order::factory()->forRestaurant($this->a)->placedAt('2026-09-05 10:00')->delivered('2026-09-05 11:00')
            ->discounted(1_000_00, 500_00)->create(['promo_code_id' => $promo->id]);
        Order::factory()->forRestaurant($this->a)->placedAt('2026-09-06 10:00')->delivered('2026-09-06 11:00')
            ->discounted(2_000_00, 1_000_00)->create(['promo_code_id' => $promo->id]);
        Order::factory()->forRestaurant($this->b)->placedAt('2026-09-07 10:00')->delivered('2026-09-07 11:00')
            ->discounted(5_000_00)->create(['promo_code_id' => $promo->id]); // hammasini platforma

        $rows = $this->stats->platformDiscounts($this->period());

        $this->assertSame(['Beta', 'Alfa'], $rows->pluck('name')->all()); // platforma qarzi bo'yicha
        $alfa = $rows->firstWhere('name', 'Alfa');
        $this->assertSame(2, $alfa['orders']);
        $this->assertSame(3_000_00, $alfa['discount_tiyin']);
        $this->assertSame(1_500_00, $alfa['restaurant_amount_tiyin']);
        $this->assertSame(1_500_00, $alfa['platform_amount_tiyin']);
        $this->assertSame(5_000_00, $rows->firstWhere('name', 'Beta')['platform_amount_tiyin']);
    }

    public function test_platform_discounts_exclude_orders_without_a_discount(): void
    {
        Order::factory()->forRestaurant($this->a)->placedAt('2026-09-05 10:00')->delivered()->create();

        $this->assertCount(0, $this->stats->platformDiscounts($this->period()));
    }

    /** Bekor qilingan buyurtmada chegirma xarajati haqiqatda sodir bo'lmagan — hisob-kitobga kirmaydi. */
    public function test_platform_discounts_exclude_cancelled_and_unfinished_orders(): void
    {
        Order::factory()->forRestaurant($this->a)->placedAt('2026-09-05 10:00')->delivered('2026-09-05 11:00')
            ->discounted(1_000_00)->create();
        Order::factory()->forRestaurant($this->a)->placedAt('2026-09-06 10:00')->cancelled()
            ->discounted(9_999_00)->create();
        Order::factory()->forRestaurant($this->a)->placedAt('2026-09-07 10:00')
            ->discounted(9_999_00)->create(['status' => OrderStatus::New]);

        $rows = $this->stats->platformDiscounts($this->period());

        $this->assertCount(1, $rows);
        $this->assertSame(1_000_00, $rows[0]['platform_amount_tiyin']);
    }

    public function test_platform_discounts_respect_the_period_and_restaurant_filter(): void
    {
        Order::factory()->forRestaurant($this->a)->placedAt('2026-09-10 10:00')->delivered('2026-09-10 11:00')
            ->discounted(1_000_00)->create();
        // Oraliqdan tashqarida — hisobga olinmaydi.
        Order::factory()->forRestaurant($this->a)->placedAt('2026-08-01 10:00')->delivered('2026-08-01 11:00')
            ->discounted(9_999_00)->create();

        $this->assertCount(1, $this->stats->platformDiscounts($this->period()));
        $this->assertCount(1, $this->stats->platformDiscounts($this->period(), $this->a->id));
        $this->assertCount(0, $this->stats->platformDiscounts($this->period(), $this->b->id));
    }

    /**
     * Promokod o'chirilsa promo_code_id NULL bo'ladi (nullOnDelete), lekin platforma
     * qarzi yo'qolmasligi kerak — hisobot faqat snapshot'ga tayanadi.
     */
    public function test_platform_debt_survives_deleting_the_promo_code(): void
    {
        $promo = PromoCode::factory()->create();
        Order::factory()->forRestaurant($this->a)->placedAt('2026-09-05 10:00')->delivered('2026-09-05 11:00')
            ->discounted(4_000_00)->create(['promo_code_id' => $promo->id]);

        $promo->delete();

        $this->assertSame(4_000_00, $this->stats->platformDiscounts($this->period())[0]['platform_amount_tiyin']);
    }
}
