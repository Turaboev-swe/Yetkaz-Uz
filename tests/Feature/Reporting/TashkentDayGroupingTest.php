<?php

namespace Tests\Feature\Reporting;

use App\Enums\OrderStatus;
use App\Filament\Admin\Resources\UserResource\Pages\ListUsers;
use App\Filament\Admin\Widgets\UsersOverviewStats;
use App\Filament\Restaurant\Pages\Ratings;
use App\Models\District;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Models\User;
use App\Services\Reporting\OrderStatsService;
use App\Services\Reporting\ReportPeriod;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * REPORT-3 — kunlik guruhlash Toshkent kalendar kuni bo'yicha.
 *
 * `orders.created_at` — UTC qiymatli `timestamp without time zone`. Eski ifoda
 * `(created_at AT TIME ZONE 'Asia/Tashkent')::date` uni "allaqachon Toshkent
 * vaqti" deb talqin qilardi: Toshkent 00:00–09:59 dagi buyurtma kechaga tushardi
 * (08:00 dagisi — UTC 03:00 — "kecha 22:00" bo'lib qolardi).
 */
class TashkentDayGroupingTest extends TestCase
{
    use RefreshDatabase;

    private OrderStatsService $stats;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stats = app(OrderStatsService::class);
        $this->restaurant = Restaurant::factory()->for(District::factory())->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Toshkent mahalliy vaqti -> bazaga yoziladigan UTC. */
    private static function tashkent(string $local): Carbon
    {
        return Carbon::parse($local, 'Asia/Tashkent')->utc();
    }

    private function orderAt(string $tashkentLocal, OrderStatus $status = OrderStatus::New): Order
    {
        return Order::factory()->forRestaurant($this->restaurant)
            ->placedAt(self::tashkent($tashkentLocal))
            ->create(['status' => $status]);
    }

    /** @return array<string, int> */
    private function perDay(ReportPeriod $period): array
    {
        return $this->stats->ordersPerDay($period, $this->restaurant->id)->pluck('orders', 'date')->all();
    }

    public function test_morning_orders_land_on_the_same_tashkent_day(): void
    {
        // Real holat: bugun 08:00 va 10:00 (Toshkent) — ikkalasi ham bugun.
        $this->orderAt('2026-10-02 08:00');
        $this->orderAt('2026-10-02 10:00');

        $this->assertSame(
            ['2026-10-01' => 0, '2026-10-02' => 2],
            $this->perDay(ReportPeriod::custom('2026-10-01', '2026-10-02')),
        );
    }

    public function test_midnight_boundaries_land_on_the_correct_day(): void
    {
        $this->orderAt('2026-10-01 23:30'); // 1-oktabr (UTC 18:30)
        $this->orderAt('2026-10-02 00:30'); // 2-oktabr (UTC 1-oktabr 19:30)
        $this->orderAt('2026-10-02 23:30'); // 2-oktabr
        $this->orderAt('2026-10-03 00:30'); // 3-oktabr

        $this->assertSame(
            ['2026-10-01' => 1, '2026-10-02' => 2, '2026-10-03' => 1],
            $this->perDay(ReportPeriod::custom('2026-10-01', '2026-10-03')),
        );
    }

    public function test_chart_daily_counts_match_the_report_summary_of_each_day(): void
    {
        foreach (['2026-10-01 00:30', '2026-10-01 08:00', '2026-10-01 23:30', '2026-10-02 04:59',
            '2026-10-02 05:00', '2026-10-02 10:00', '2026-10-03 00:00', '2026-10-03 19:30'] as $at) {
            $this->orderAt($at, OrderStatus::Delivered);
        }

        $series = $this->perDay(ReportPeriod::custom('2026-10-01', '2026-10-03'));

        $this->assertSame(['2026-10-01' => 3, '2026-10-02' => 3, '2026-10-03' => 2], $series);
        foreach ($series as $date => $count) {
            $summary = $this->stats->summary(ReportPeriod::custom($date, $date), $this->restaurant->id);
            $this->assertSame($summary['orders'], $count, "{$date}: grafik va hisobot farq qiladi");
            $this->assertSame($summary['delivered'], $count);
        }
    }

    public function test_dashboard_range_ends_on_tashkent_today_even_after_midnight(): void
    {
        // Toshkent 2-oktabr 02:00 = UTC 1-oktabr 21:00. Vidjetlar now() (UTC) uzatadi.
        Carbon::setTestNow(self::tashkent('2026-10-02 02:00'));
        $this->orderAt('2026-10-02 01:30');

        $period = ReportPeriod::custom(now()->subDays(29), now());

        $this->assertSame('2026-10-02', $period->to->format('Y-m-d'));
        $this->assertCount(30, $period->dates());
        $this->assertSame('2026-09-03', $period->dates()[0]);
        $this->assertSame(1, $this->perDay($period)['2026-10-02']);
    }

    public function test_users_widget_today_counts_tashkent_morning_registrations(): void
    {
        Carbon::setTestNow(self::tashkent('2026-10-02 12:00'));
        User::factory()->create(['created_at' => self::tashkent('2026-10-02 02:00')]); // bugun
        User::factory()->create(['created_at' => self::tashkent('2026-10-02 08:00')]); // bugun
        User::factory()->create(['created_at' => self::tashkent('2026-10-01 23:30')]); // kecha
        User::factory()->create(['created_at' => self::tashkent('2026-10-01 01:00')]); // kecha, oktabr (UTC 30-sentabr)
        User::factory()->create(['created_at' => self::tashkent('2026-09-30 23:00')]); // sentabr

        $method = new ReflectionMethod(UsersOverviewStats::class, 'getStats');
        $stats = collect($method->invoke(new UsersOverviewStats))
            ->mapWithKeys(fn (Stat $s) => [(string) $s->getLabel() => $s]);

        $this->assertSame('2', (string) $stats["Bugun ro'yxatdan o'tgan"]->getValue());
        $this->assertSame('4', (string) $stats["Shu oy ro'yxatdan o'tgan"]->getValue());
        // Grafikning oxirgi uch kuni: 30-sentabr, 1-oktabr, 2-oktabr (Toshkent).
        $this->assertSame([1, 2, 2], array_slice($stats['Jami mijozlar']->getChart(), -3));
    }

    public function test_user_list_date_filter_uses_tashkent_days(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs(Staff::factory()->platformAdmin()->create(), 'admin');

        $inside = User::factory()->create(['created_at' => self::tashkent('2026-10-02 02:00')]);  // UTC 1-okt
        $outside = User::factory()->create(['created_at' => self::tashkent('2026-10-03 02:00')]); // UTC 2-okt

        Livewire::test(ListUsers::class)
            ->filterTable('registered_between', ['from' => '2026-10-02', 'until' => '2026-10-02'])
            ->assertCanSeeTableRecords([$inside])
            ->assertCanNotSeeTableRecords([$outside]);
    }

    public function test_ratings_date_filter_uses_tashkent_days(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('restaurant'));
        Livewire::actingAs(Staff::factory()->owner($this->restaurant)->create(), 'staff');

        $rated = fn (string $at) => Order::factory()->for($this->restaurant)->for(User::factory())->create([
            'status' => OrderStatus::Delivered, 'rating' => 5, 'rated_at' => self::tashkent($at),
        ]);
        $inside = $rated('2026-10-02 02:00');
        $outside = $rated('2026-10-03 02:00');

        Livewire::test(Ratings::class)
            ->filterTable('rated_between', ['from' => '2026-10-02', 'until' => '2026-10-02'])
            ->assertCanSeeTableRecords([$inside])
            ->assertCanNotSeeTableRecords([$outside]);
    }
}
