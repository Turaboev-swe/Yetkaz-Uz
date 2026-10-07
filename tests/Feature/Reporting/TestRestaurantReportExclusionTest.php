<?php

namespace Tests\Feature\Reporting;

use App\Filament\Admin\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Admin\Resources\UserResource\Pages\ListUsers;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Models\User;
use App\Services\Reporting\OrderStatsService;
use App\Services\Reporting\ReportPeriod;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Test restoran (is_test) buyurtmalari platforma hisobot va statistikasiga
 * KIRMAYDI (dashboard, Hisobotlar, dinamika, Faol/Qaytgan mijozlar, Jami xarid,
 * Platforma chegirmalari). Admin buyurtmalar ro'yxatida esa ko'rinadi —
 * "🧪 Test" belgisi va filtri bilan.
 */
class TestRestaurantReportExclusionTest extends TestCase
{
    use RefreshDatabase;

    private OrderStatsService $stats;

    private Restaurant $real;

    private Restaurant $test;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-25 12:00:00');
        $this->stats = app(OrderStatsService::class);
        $this->real = Restaurant::factory()->create(['name' => 'Haqiqiy']);
        $this->test = Restaurant::factory()->create(['name' => 'Yetkaz Test', 'is_test' => true]);
        $this->customer = User::factory()->create();

        $items = [['product_id' => 1, 'name' => 'Burger', 'price' => 30_000_00, 'qty' => 1]];

        Order::factory()->forRestaurant($this->real)->for($this->customer)->items($items)
            ->placedAt('2026-09-05 10:00')->delivered('2026-09-05 11:00')
            ->discounted(10_000_00)->create(['total' => 40_000_00]);

        // Test restoran: 2 ta yetkazilgan (biri chegirmali) — hech biri hisobotga kirmasligi kerak.
        Order::factory()->count(2)->forRestaurant($this->test)->for($this->customer)->items($items)
            ->placedAt('2026-09-06 10:00')->delivered('2026-09-06 11:00')
            ->discounted(5_000_00)->create(['total' => 99_000_00]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function period(): ReportPeriod
    {
        return ReportPeriod::custom('2026-09-01', '2026-09-30');
    }

    public function test_platform_summary_and_daily_chart_exclude_test_orders(): void
    {
        $s = $this->stats->summary($this->period());

        $this->assertSame(1, $s['orders']);
        $this->assertSame(1, $s['delivered']);
        $this->assertSame(1, $s['customers']);

        $perDay = $this->stats->ordersPerDay($this->period())->pluck('orders', 'date');
        $this->assertSame(1, $perDay['2026-09-05']);
        $this->assertSame(0, $perDay['2026-09-06']);
    }

    public function test_top_restaurants_products_kitchen_and_discounts_exclude_test_restaurant(): void
    {
        $this->assertSame([$this->real->id], $this->stats->topRestaurants($this->period())->pluck('restaurant_id')->all());
        $this->assertSame([$this->real->id], $this->stats->kitchenPerformance($this->period())->pluck('restaurant_id')->all());
        $this->assertSame([$this->real->id], $this->stats->platformDiscounts($this->period())->pluck('restaurant_id')->all());
        $this->assertSame(1, $this->stats->topProducts($this->period())->sole()['qty']);
    }

    public function test_test_restaurant_still_sees_its_own_stats_in_its_panel(): void
    {
        $this->assertSame(2, $this->stats->summary($this->period(), $this->test->id)['orders']);
    }

    public function test_active_and_returning_customers_ignore_test_orders(): void
    {
        $onlyTest = User::factory()->create();
        Order::factory()->count(2)->forRestaurant($this->test)->for($onlyTest)->delivered('2026-09-20 11:00')->create();

        $this->assertFalse(User::query()->activeSince(30)->whereKey($onlyTest->id)->exists());
        $this->assertFalse(User::query()->returning()->whereKey($onlyTest->id)->exists());
        $this->assertFalse(User::query()->inactiveFor(1)->whereKey($onlyTest->id)->exists());
        // $customer'da 1 ta haqiqiy + 2 ta test yetkazilgan — "qaytgan" emas.
        $this->assertFalse(User::query()->returning()->whereKey($this->customer->id)->exists());
    }

    public function test_customer_list_total_spent_and_order_count_ignore_test_orders(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs(Staff::factory()->platformAdmin()->create(), 'admin');

        Livewire::test(ListUsers::class)
            ->assertTableColumnStateSet('orders_count', 1, $this->customer)
            ->assertTableColumnStateSet('delivered_total_tiyin', 40_000_00, $this->customer);
    }

    public function test_admin_order_list_shows_test_orders_with_badge_and_filter(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs(Staff::factory()->platformAdmin()->create(), 'admin');

        $real = Order::query()->where('restaurant_id', $this->real->id)->get();
        $test = Order::query()->where('restaurant_id', $this->test->id)->get();

        Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords($real->merge($test))
            ->assertSee('🧪 Test · Yetkaz Test')
            ->filterTable('is_test', true)
            ->assertCanSeeTableRecords($test)
            ->assertCanNotSeeTableRecords($real)
            ->filterTable('is_test', false)
            ->assertCanSeeTableRecords($real)
            ->assertCanNotSeeTableRecords($test);
    }
}
