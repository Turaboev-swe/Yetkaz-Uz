<?php

namespace Tests\Feature\Reporting;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;
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
        Order::factory()->forRestaurant($this->a)->placedAt('2026-09-15 10:00')->items([
            ['product_id' => 25, 'name' => 'Grill burger', 'price' => 34_000_00, 'qty' => 2],
            ['product_id' => 28, 'name' => 'Fri kartoshka', 'price' => 13_000_00, 'qty' => 1],
        ])->create();
        Order::factory()->forRestaurant($this->a)->placedAt('2026-09-16 10:00')->items([
            ['product_id' => 25, 'name' => 'Grill burger', 'price' => 34_000_00, 'qty' => 3],
        ])->create();

        $top = $this->stats->topProducts($this->period(), $this->a->id);

        $this->assertSame(25, $top[0]['product_id']);
        $this->assertSame(5, $top[0]['qty']);
        $this->assertSame(5 * 34_000_00, $top[0]['revenue_tiyin']);
        $this->assertSame('Fri kartoshka', $top[1]['name']);
        $this->assertSame(1, $top[1]['qty']);
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

    public function test_restaurant_scope_excludes_other_restaurants(): void
    {
        Order::factory()->count(2)->forRestaurant($this->a)->placedAt('2026-09-10 10:00')->create();
        Order::factory()->count(9)->forRestaurant($this->b)->placedAt('2026-09-10 10:00')->create();

        $this->assertSame(2, $this->stats->summary($this->period(), $this->a->id)['orders']);
        $this->assertSame(9, $this->stats->summary($this->period(), $this->b->id)['orders']);
        $this->assertSame(11, $this->stats->summary($this->period(), null)['orders']);
    }
}
