<?php

namespace Tests\Feature\Stock;

use App\Events\OrderStatusChanged;
use App\Events\ProductStockChanged;
use App\Exceptions\InsufficientStockException;
use App\Models\Category;
use App\Models\District;
use App\Models\Order;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Ordering\OrderStatusService;
use App\Services\Stock\StockService;
use Illuminate\Broadcasting\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Qoldiq o'zgarganda Reverb hodisasi: commit'dan keyin, restoran bo'yicha bitta, faqat o'zgarganlar uchun. */
class StockEventTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Event::fake([ProductStockChanged::class, OrderStatusChanged::class]);

        $this->restaurant = Restaurant::factory()->for(District::factory())->create();
        $this->category = Category::factory()->for($this->restaurant)->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(?int $stock, string $name = 'Osh', array $extra = []): Product
    {
        return Product::factory()->for($this->category)->create(array_replace(['name' => $name, 'stock_quantity' => $stock], $extra));
    }

    private function line(Product $p, int $qty): array
    {
        return ['product_id' => $p->id, 'name' => $p->name, 'qty' => $qty];
    }

    public function test_the_event_uses_a_public_menu_channel_and_a_small_payload(): void
    {
        $event = new ProductStockChanged(7, [['id' => 1, 'stock' => 2, 'sold_out' => false, 'low_stock' => true]]);

        $channels = $event->broadcastOn();
        $this->assertInstanceOf(Channel::class, $channels[0]);
        $this->assertSame('menu.7', $channels[0]->name);
        $this->assertSame('product.stock', $event->broadcastAs());
        $this->assertSame(['restaurant_id' => 7, 'products' => [['id' => 1, 'stock' => 2, 'sold_out' => false, 'low_stock' => true]]], $event->broadcastWith());
    }

    public function test_reserving_announces_the_new_stock_once_per_restaurant(): void
    {
        $osh = $this->product(10);
        $non = $this->product(2, 'Non');

        DB::transaction(fn () => app(StockService::class)->reserve([$this->line($osh, 3), $this->line($non, 2)]));

        Event::assertDispatchedTimes(ProductStockChanged::class, 1);
        Event::assertDispatched(ProductStockChanged::class, function (ProductStockChanged $e) use ($osh, $non) {
            $byId = collect($e->products)->keyBy('id');

            return $e->restaurantId === $this->restaurant->id
                && $byId[$osh->id]['stock'] === 7 && $byId[$osh->id]['sold_out'] === false
                && $byId[$non->id]['stock'] === 0 && $byId[$non->id]['sold_out'] === true;
        });
    }

    public function test_low_stock_flag_is_part_of_the_payload(): void
    {
        $osh = $this->product(9);

        DB::transaction(fn () => app(StockService::class)->reserve([$this->line($osh, 5)]));   // 4 qoldi (<= 5)

        Event::assertDispatched(ProductStockChanged::class, fn (ProductStockChanged $e) => $e->products[0]['low_stock'] === true && $e->products[0]['stock'] === 4);
    }

    public function test_nothing_is_announced_for_unlimited_products(): void
    {
        $cheksiz = $this->product(null);

        DB::transaction(fn () => app(StockService::class)->reserve([$this->line($cheksiz, 5)]));

        Event::assertNotDispatched(ProductStockChanged::class);
    }

    public function test_a_refused_reserve_announces_nothing(): void
    {
        $osh = $this->product(1);

        try {
            DB::transaction(fn () => app(StockService::class)->reserve([$this->line($osh, 3)]));
        } catch (InsufficientStockException) {
            // kutilgan
        }

        Event::assertNotDispatched(ProductStockChanged::class);
    }

    public function test_a_rolled_back_transaction_announces_nothing(): void
    {
        $osh = $this->product(5);

        try {
            DB::transaction(function () use ($osh) {
                app(StockService::class)->reserve([$this->line($osh, 2)]);
                throw new \RuntimeException('keyingi qadam yiqildi');
            });
        } catch (\RuntimeException) {
            // kutilgan
        }

        Event::assertNotDispatched(ProductStockChanged::class);
        $this->assertSame(5, (int) DB::table('products')->where('id', $osh->id)->value('stock_quantity'));
    }

    public function test_cancelling_an_order_announces_the_returned_stock(): void
    {
        $osh = $this->product(10);
        $reserved = DB::transaction(fn () => app(StockService::class)->reserve([$this->line($osh, 4)]));
        $order = Order::factory()->for($this->restaurant)->for(User::factory())->create(['stock_reserved' => $reserved, 'status' => 'accepted']);
        Event::assertDispatchedTimes(ProductStockChanged::class, 1);

        app(OrderStatusService::class)->cancel($order, 'Sabab', 'kitchen:1');

        Event::assertDispatchedTimes(ProductStockChanged::class, 2);
        Event::assertDispatched(ProductStockChanged::class, fn (ProductStockChanged $e) => $e->products[0]['stock'] === 10);
    }

    public function test_a_repeated_release_announces_nothing_new(): void
    {
        $osh = $this->product(10);
        $reserved = DB::transaction(fn () => app(StockService::class)->reserve([$this->line($osh, 4)]));
        $order = Order::factory()->for($this->restaurant)->for(User::factory())->create(['stock_reserved' => $reserved]);

        app(StockService::class)->release($order);
        app(StockService::class)->release($order);

        Event::assertDispatchedTimes(ProductStockChanged::class, 2);   // reserve + birinchi release; ikkinchisi — jim
    }

    public function test_the_daily_reset_announces_the_restored_stock(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-03 06:00:10', 'Asia/Tashkent'));
        $osh = $this->product(0, 'Osh', ['daily_stock' => 30, 'daily_reset_time' => '06:00:00']);
        DB::table('products')->where('id', $osh->id)->update(['stock_reset_at' => '2026-11-02 01:00:00']);
        Event::assertNotDispatched(ProductStockChanged::class);

        app(StockService::class)->resetDaily();

        Event::assertDispatched(ProductStockChanged::class, fn (ProductStockChanged $e) => $e->products[0]['stock'] === 30 && $e->products[0]['sold_out'] === false);
    }

    public function test_a_manual_stock_edit_is_announced_but_other_edits_are_not(): void
    {
        $osh = $this->product(5);

        $osh->update(['name' => 'Yangi nom']);
        Event::assertNotDispatched(ProductStockChanged::class);

        $osh->update(['stock_quantity' => 0]);

        Event::assertDispatchedTimes(ProductStockChanged::class, 1);
        Event::assertDispatched(ProductStockChanged::class, fn (ProductStockChanged $e) => $e->products[0]['sold_out'] === true);
    }

    public function test_products_of_different_restaurants_are_announced_separately(): void
    {
        $other = Restaurant::factory()->for(District::factory())->create();
        $a = $this->product(5);
        $b = Product::factory()->for(Category::factory()->for($other)->create())->create(['name' => 'Boshqa', 'stock_quantity' => 5]);

        DB::transaction(fn () => app(StockService::class)->reserve([$this->line($a, 1), $this->line($b, 1)]));

        Event::assertDispatchedTimes(ProductStockChanged::class, 2);
        Event::assertDispatched(ProductStockChanged::class, fn (ProductStockChanged $e) => $e->restaurantId === $this->restaurant->id);
        Event::assertDispatched(ProductStockChanged::class, fn (ProductStockChanged $e) => $e->restaurantId === $other->id);
    }
}
