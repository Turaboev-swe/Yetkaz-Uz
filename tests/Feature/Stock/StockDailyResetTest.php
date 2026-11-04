<?php

namespace Tests\Feature\Stock;

use App\Events\OrderStatusChanged;
use App\Models\Category;
use App\Models\District;
use App\Models\Order;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Ordering\OrderStatusService;
use App\Services\Stock\StockService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Kunlik qoldiq: vaqti kelganda tiklanadi, kuniga bir marta, Asia/Tashkent, scheduler o'tkazib yuborsa ham. */
class StockDailyResetTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Event::fake([OrderStatusChanged::class]);   // faqat Reverb hodisasi; Eloquent observer'lar (ProductObserver) ishlaydi

        $this->restaurant = Restaurant::factory()->for(District::factory())->create();
        $this->category = Category::factory()->for($this->restaurant)->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Toshkent devor soati bilan "hozir". */
    private function at(string $local): void
    {
        Carbon::setTestNow(Carbon::parse($local, 'Asia/Tashkent'));
    }

    private function daily(int $stock, int $daily, string $time, ?string $lastResetUtc = null, string $name = 'Osh'): Product
    {
        $product = Product::factory()->for($this->category)->create([
            'name' => $name, 'stock_quantity' => $stock, 'daily_stock' => $daily, 'daily_reset_time' => $time,
        ]);
        // Observer yaratilganda stock_reset_at = hozir qo'yadi; testda aniq lahzani beramiz.
        DB::table('products')->where('id', $product->id)->update(['stock_reset_at' => $lastResetUtc]);

        return $product;
    }

    private function stock(Product $p): ?int
    {
        return DB::table('products')->where('id', $p->id)->value('stock_quantity');
    }

    private function reset(): array
    {
        return app(StockService::class)->resetDaily();
    }

    public function test_the_stock_is_restored_when_the_reset_time_arrives(): void
    {
        $this->at('2026-11-03 06:00:10');
        $osh = $this->daily(2, 30, '06:00:00', '2026-11-02 01:00:00');   // kecha 06:00 Toshkent = 01:00 UTC

        $reset = $this->reset();

        $this->assertSame([$osh->id], $reset);
        $this->assertSame(30, $this->stock($osh));
        $this->assertTrue(Carbon::parse(DB::table('products')->where('id', $osh->id)->value('stock_reset_at'), 'UTC')->equalTo(now()));
    }

    public function test_nothing_happens_before_the_reset_time(): void
    {
        $this->at('2026-11-03 05:59:59');
        $osh = $this->daily(2, 30, '06:00:00', '2026-11-02 01:00:00');

        $this->assertSame([], $this->reset());
        $this->assertSame(2, $this->stock($osh));
    }

    public function test_a_product_is_restored_only_once_a_day(): void
    {
        $this->at('2026-11-03 06:00:10');
        $osh = $this->daily(2, 30, '06:00:00', '2026-11-02 01:00:00');
        $this->reset();

        DB::table('products')->where('id', $osh->id)->update(['stock_quantity' => 11]);   // kun davomida sotildi

        $this->at('2026-11-03 06:01:10');
        $this->assertSame([], $this->reset(), 'keyingi daqiqada qayta tiklanmaydi');
        $this->at('2026-11-03 18:30:00');
        $this->assertSame([], $this->reset());
        $this->assertSame(11, $this->stock($osh));
    }

    public function test_it_is_restored_again_the_next_day(): void
    {
        $this->at('2026-11-03 06:00:10');
        $osh = $this->daily(2, 30, '06:00:00', '2026-11-02 01:00:00');
        $this->reset();
        DB::table('products')->where('id', $osh->id)->update(['stock_quantity' => 5]);

        $this->at('2026-11-04 06:00:30');

        $this->assertSame([$osh->id], $this->reset());
        $this->assertSame(30, $this->stock($osh));
    }

    public function test_a_missed_minute_is_caught_up_later_the_same_day(): void
    {
        $this->at('2026-11-03 09:17:00');   // scheduler 06:00 da ishlamagan edi
        $osh = $this->daily(2, 30, '06:00:00', '2026-11-02 01:00:00');

        $this->assertSame([$osh->id], $this->reset());
        $this->assertSame(30, $this->stock($osh));
    }

    public function test_the_reset_time_is_tashkent_time_not_utc(): void
    {
        // 06:00 Toshkent = 01:00 UTC.
        Carbon::setTestNow(Carbon::parse('2026-11-03 00:59:30', 'UTC'));   // 05:59:30 Toshkent
        $osh = $this->daily(2, 30, '06:00:00', '2026-11-02 01:00:00');
        $this->assertSame([], $this->reset(), 'UTC 00:59 — Toshkentda hali 05:59');

        Carbon::setTestNow(Carbon::parse('2026-11-03 01:00:05', 'UTC'));   // 06:00:05 Toshkent
        $this->assertSame([$osh->id], $this->reset());
        $this->assertSame(30, $this->stock($osh));
    }

    public function test_a_late_evening_reset_time_works_across_the_utc_date_boundary(): void
    {
        // 01:30 Toshkent (04.11) = 20:30 UTC (03.11): UTC sanasi boshqa, tiklanish esa Toshkent sanasi bo'yicha.
        $this->at('2026-11-04 01:30:00');
        $osh = $this->daily(1, 9, '01:00:00', '2026-11-02 20:00:00');   // oxirgi tiklanish: 03.11 01:00 Toshkent (kecha)

        $this->assertSame([$osh->id], $this->reset());
        $this->assertSame(9, $this->stock($osh));
    }

    public function test_a_product_with_no_previous_reset_is_restored_when_its_time_has_passed(): void
    {
        $this->at('2026-11-03 10:00:00');
        $osh = $this->daily(0, 15, '06:00:00', null);

        $this->assertSame([$osh->id], $this->reset());
        $this->assertSame(15, $this->stock($osh));
    }

    public function test_products_without_a_daily_setting_are_never_touched(): void
    {
        $this->at('2026-11-03 12:00:00');
        $plain = Product::factory()->for($this->category)->create(['stock_quantity' => 4]);
        $unlimited = Product::factory()->for($this->category)->create(['stock_quantity' => null]);

        $this->assertSame([], $this->reset());
        $this->assertSame(4, $this->stock($plain));
        $this->assertNull($this->stock($unlimited));
    }

    public function test_several_products_with_different_times_are_handled_independently(): void
    {
        $this->at('2026-11-03 08:00:00');
        $early = $this->daily(1, 10, '06:00:00', '2026-11-02 01:00:00', 'Erta');
        $late = $this->daily(1, 20, '12:00:00', '2026-11-02 07:00:00', 'Kech');

        $this->assertSame([$early->id], $this->reset());
        $this->assertSame(10, $this->stock($early));
        $this->assertSame(1, $this->stock($late));

        $this->at('2026-11-03 12:00:05');
        $this->assertSame([$late->id], $this->reset());
        $this->assertSame(20, $this->stock($late));
    }

    public function test_saving_the_setting_after_todays_time_does_not_overwrite_the_stock_until_tomorrow(): void
    {
        $this->at('2026-11-03 10:00:00');
        // Egasi soat 10:00 da "kunlik 30, 06:00" ni saqladi va hozirgi qoldiqni 7 qildi — Observer stock_reset_at = hozir.
        $osh = Product::factory()->for($this->category)->create(['stock_quantity' => 7, 'daily_stock' => 30, 'daily_reset_time' => '06:00:00']);

        $this->at('2026-11-03 10:01:00');
        $this->assertSame([], $this->reset());
        $this->assertSame(7, $this->stock($osh), 'bugun qayta yozilmaydi');

        $this->at('2026-11-04 06:00:30');
        $this->assertSame([$osh->id], $this->reset());
        $this->assertSame(30, $this->stock($osh));
    }

    public function test_the_second_of_two_overlapping_runs_does_nothing(): void
    {
        $this->at('2026-11-03 06:00:10');
        $osh = $this->daily(2, 30, '06:00:00', '2026-11-02 01:00:00');

        $this->assertSame([$osh->id], $this->reset());
        DB::table('products')->where('id', $osh->id)->update(['stock_quantity' => 1]);
        $this->assertSame([], $this->reset(), 'ikkinchi oqim: shartli UPDATE 0 qator');
        $this->assertSame(1, $this->stock($osh));
    }

    public function test_an_order_from_before_the_reset_does_not_raise_the_new_days_stock_when_cancelled(): void
    {
        $this->at('2026-11-03 07:00:00');
        $osh = $this->daily(10, 10, '06:00:00', '2026-11-03 01:00:00');   // bugun 06:00 tiklangan
        $reserved = app(StockService::class)->reserve([['product_id' => $osh->id, 'name' => 'Osh', 'qty' => 4]]);
        $order = Order::factory()->for($this->restaurant)->for(User::factory())->create([
            'stock_reserved' => $reserved, 'status' => 'accepted', 'created_at' => now(),
        ]);
        $this->assertSame(6, $this->stock($osh));

        $this->at('2026-11-04 06:00:30');   // yangi kun: qoldiq 10 ga tiklandi
        $this->assertSame([$osh->id], $this->reset());
        $this->assertSame(10, $this->stock($osh));

        $this->at('2026-11-04 08:00:00');   // kechagi buyurtma endi bekor qilinadi
        app(OrderStatusService::class)->cancel($order->fresh(), 'Sabab', 'kitchen:1');

        $this->assertSame(10, $this->stock($osh), 'yangi kunning qoldig‘i kechagi buyurtma bilan oshmaydi');
    }

    public function test_an_order_placed_after_the_reset_is_returned_when_cancelled_the_same_day(): void
    {
        $this->at('2026-11-03 06:00:30');
        $osh = $this->daily(10, 10, '06:00:00', '2026-11-02 01:00:00');
        $this->reset();

        $this->at('2026-11-03 09:00:00');
        $reserved = app(StockService::class)->reserve([['product_id' => $osh->id, 'name' => 'Osh', 'qty' => 4]]);
        $order = Order::factory()->for($this->restaurant)->for(User::factory())->create([
            'stock_reserved' => $reserved, 'status' => 'accepted', 'created_at' => now(),
        ]);

        $this->at('2026-11-03 10:00:00');
        app(OrderStatusService::class)->cancel($order->fresh(), 'Sabab', 'kitchen:1');

        $this->assertSame(10, $this->stock($osh));
    }

    // --- Buyruq va scheduler ----------------------------------------------------------------------------

    public function test_the_command_resets_and_reports(): void
    {
        $this->at('2026-11-03 06:00:10');
        $osh = $this->daily(2, 30, '06:00:00', '2026-11-02 01:00:00');

        $this->artisan('stock:reset-daily')->expectsOutput('Kunlik qoldiq tiklandi: 1 ta mahsulot.')->assertSuccessful();
        $this->assertSame(30, $this->stock($osh));

        $this->artisan('stock:reset-daily')->doesntExpectOutput('Kunlik qoldiq tiklandi: 1 ta mahsulot.')->assertSuccessful();
    }

    public function test_the_command_is_scheduled_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'stock:reset-daily'));

        $this->assertNotNull($event, 'scheduler ro‘yxatida stock:reset-daily bor');
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
