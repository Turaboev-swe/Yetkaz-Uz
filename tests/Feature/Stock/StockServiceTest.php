<?php

namespace Tests\Feature\Stock;

use App\Enums\OrderStatus;
use App\Exceptions\InsufficientStockException;
use App\Models\Category;
use App\Models\District;
use App\Models\Order;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Stock\StockService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Qoldiq (stock): atomik band qilish, qaytarish (idempotent), NULL = cheklanmagan, bazadagi cheklovlar. */
class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->restaurant = Restaurant::factory()->for(District::factory())->create();
        $this->category = Category::factory()->for($this->restaurant)->create();
    }

    private function product(?int $stock, string $name = 'Osh', array $extra = []): Product
    {
        return Product::factory()->for($this->category)->create(array_replace(['name' => $name, 'stock_quantity' => $stock], $extra));
    }

    private function stock(Product $p): ?int
    {
        return DB::table('products')->where('id', $p->id)->value('stock_quantity');
    }

    private function line(Product $p, int $qty): array
    {
        return ['product_id' => $p->id, 'name' => $p->name, 'qty' => $qty];
    }

    private function orderReserving(array $reserved, array $extra = []): Order
    {
        return Order::factory()->for($this->restaurant)->for(User::factory())->create(array_replace(['stock_reserved' => $reserved], $extra));
    }

    private function service(): StockService
    {
        return app(StockService::class);
    }

    // --- band qilish ---------------------------------------------------------------------------------

    public function test_reserve_decrements_limited_stock_and_reports_what_it_took(): void
    {
        $osh = $this->product(10);
        $non = $this->product(3, 'Non');

        $reserved = $this->service()->reserve([$this->line($osh, 4), $this->line($non, 3)]);

        $this->assertSame([$osh->id => 4, $non->id => 3], $reserved);
        $this->assertSame(6, $this->stock($osh));
        $this->assertSame(0, $this->stock($non), 'aynan qoldiqqa teng miqdor — ruxsat');
    }

    public function test_null_stock_means_unlimited_and_is_never_touched(): void
    {
        $unlimited = $this->product(null, 'Cheksiz');

        $reserved = $this->service()->reserve([$this->line($unlimited, 50)]);

        $this->assertSame([], $reserved, 'cheksiz mahsulot band qilinmaydi — qaytarishda ham tegilmaydi');
        $this->assertNull($this->stock($unlimited));
        $this->assertFalse($unlimited->fresh()->hasLimitedStock());
        $this->assertFalse($unlimited->fresh()->isSoldOut());
    }

    public function test_existing_style_products_without_the_new_columns_behave_as_before(): void
    {
        $plain = Product::factory()->for($this->category)->create();   // stock ustunlari berilmagan

        $this->assertNull($plain->fresh()->stock_quantity);
        $this->assertNull($plain->fresh()->daily_stock);
        $this->assertNull($plain->fresh()->daily_reset_time);
        $this->assertNull($plain->fresh()->stock_reset_at);
        $this->assertSame(1, Product::query()->inStock()->whereKey($plain->id)->count());
    }

    public function test_insufficient_stock_throws_with_the_exact_uzbek_message_and_changes_nothing(): void
    {
        $osh = $this->product(2);

        try {
            $this->service()->reserve([$this->line($osh, 3)]);
            $this->fail('qoldiq yetmasa istisno kutilgan edi');
        } catch (InsufficientStockException $e) {
            $this->assertSame('Kechirasiz, Osh dan faqat 2 ta qoldi.', $e->getMessage());
            $this->assertSame([['product_id' => $osh->id, 'name' => 'Osh', 'available' => 2]], $e->shortages);
        }

        $this->assertSame(2, $this->stock($osh));
    }

    public function test_sold_out_product_gets_the_sold_out_message(): void
    {
        $osh = $this->product(0);

        $this->expectException(InsufficientStockException::class);
        $this->expectExceptionMessage('Kechirasiz, Osh tugagan.');

        $this->service()->reserve([$this->line($osh, 1)]);
    }

    public function test_every_short_line_is_reported_at_once(): void
    {
        $osh = $this->product(1);
        $non = $this->product(0, 'Non');
        $ok = $this->product(9, 'Choy');

        try {
            $this->service()->reserve([$this->line($osh, 2), $this->line($non, 1), $this->line($ok, 1)]);
            $this->fail();
        } catch (InsufficientStockException $e) {
            $this->assertSame('Kechirasiz, Osh dan faqat 1 ta qoldi. Kechirasiz, Non tugagan.', $e->getMessage());
            $this->assertCount(2, $e->shortages);
        }
    }

    public function test_a_failed_reserve_inside_a_transaction_rolls_back_the_earlier_lines(): void
    {
        $first = $this->product(5, 'Birinchi');
        $second = $this->product(1, 'Ikkinchi');

        try {
            DB::transaction(fn () => $this->service()->reserve([$this->line($first, 2), $this->line($second, 2)]));
            $this->fail();
        } catch (InsufficientStockException) {
            // kutilgan
        }

        $this->assertSame(5, $this->stock($first), 'birinchi qatorning kamayishi ham qaytdi (hammasi yoki hech narsa)');
        $this->assertSame(1, $this->stock($second));
    }

    public function test_sold_out_and_in_stock_flags(): void
    {
        $soldOut = $this->product(0, 'Tugagan');
        $few = $this->product(2, 'Kam');
        $unlimited = $this->product(null, 'Cheksiz');

        $this->assertTrue($soldOut->isSoldOut());
        $this->assertFalse($few->isSoldOut());
        $this->assertFalse($unlimited->isSoldOut());

        $ids = Product::query()->inStock()->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$few->id, $unlimited->id], $ids);
    }

    public function test_the_database_never_lets_stock_go_negative(): void
    {
        $osh = $this->product(1);

        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('products')->where('id', $osh->id)->update(['stock_quantity' => -1]));
    }

    public function test_daily_stock_requires_a_reset_time_in_the_database(): void
    {
        $osh = $this->product(5);

        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('products')->where('id', $osh->id)->update(['daily_stock' => 20]));
    }

    // --- qaytarish ----------------------------------------------------------------------------------

    public function test_release_returns_the_reserved_stock_and_is_idempotent(): void
    {
        $osh = $this->product(10);
        $reserved = $this->service()->reserve([$this->line($osh, 4)]);
        $order = $this->orderReserving($reserved);
        $this->assertSame(6, $this->stock($osh));

        $first = $this->service()->release($order);
        $second = $this->service()->release($order);
        $third = $this->service()->release($order->fresh());

        $this->assertSame([$osh->id => 4], $first);
        $this->assertSame([], $second);
        $this->assertSame([], $third);
        $this->assertSame(10, $this->stock($osh), 'ikki marta qaytmaydi');
        $this->assertNotNull($order->fresh()->stock_released_at);
        $this->assertSame([], $order->fresh()->stock_reserved);
    }

    public function test_partial_release_returns_only_the_given_lines_and_never_more_than_was_reserved(): void
    {
        $osh = $this->product(10);
        $non = $this->product(10, 'Non');
        $order = $this->orderReserving($this->service()->reserve([$this->line($osh, 4), $this->line($non, 2)]));

        $partial = $this->service()->release($order, [$osh->id => 1]);
        $this->assertSame([$osh->id => 1], $partial);
        $this->assertSame(7, $this->stock($osh));
        $this->assertNull($order->fresh()->stock_released_at, 'hali hammasi qaytmadi');

        // 99 so'ralsa ham faqat qolgan 3 ta qaytadi.
        $again = $this->service()->release($order->fresh(), [$osh->id => 99]);
        $this->assertSame([$osh->id => 3], $again);
        $this->assertSame(10, $this->stock($osh));

        // Qolgani (Non) — to'liq bekor qilishda; Osh qayta qaytmaydi.
        $rest = $this->service()->release($order->fresh());
        $this->assertSame([$non->id => 2], $rest);
        $this->assertSame(10, $this->stock($osh));
        $this->assertSame(10, $this->stock($non));
        $this->assertNotNull($order->fresh()->stock_released_at);
    }

    public function test_release_without_any_reservation_does_nothing(): void
    {
        $osh = $this->product(5);
        $order = $this->orderReserving([], ['stock_reserved' => null]);

        $this->assertSame([], $this->service()->release($order));
        $this->assertSame(5, $this->stock($osh));
    }

    public function test_release_does_not_touch_a_product_that_became_unlimited_meanwhile(): void
    {
        $osh = $this->product(10);
        $order = $this->orderReserving($this->service()->reserve([$this->line($osh, 4)]));

        DB::table('products')->where('id', $osh->id)->update(['stock_quantity' => null]);   // restoran cheksiz qildi

        $this->assertSame([], $this->service()->release($order));
        $this->assertNull($this->stock($osh));
        $this->assertSame([], $order->fresh()->stock_reserved, 'baribir qaytarilgan hisoblanadi — qayta urinilmaydi');
    }

    public function test_an_order_placed_before_the_last_daily_reset_does_not_raise_todays_stock(): void
    {
        Carbon::setTestNow('2026-11-03 10:00:00');
        $osh = $this->product(20, 'Osh', ['daily_stock' => 20, 'daily_reset_time' => '04:00:00']);
        $order = $this->orderReserving($this->service()->reserve([$this->line($osh, 5)]), ['created_at' => '2026-11-03 09:00:00']);

        // Kunlik tiklanish buyurtmadan KEYIN bo'ldi (bugungi qoldiq yangi kun uchun 20).
        DB::table('products')->where('id', $osh->id)->update(['stock_quantity' => 20, 'stock_reset_at' => '2026-11-03 09:30:00']);

        $this->assertSame([], $this->service()->release($order));
        $this->assertSame(20, $this->stock($osh));

        Carbon::setTestNow();
    }

    public function test_an_order_placed_after_the_last_reset_is_released_normally(): void
    {
        $osh = $this->product(20, 'Osh', ['daily_stock' => 20, 'daily_reset_time' => '04:00:00']);
        DB::table('products')->where('id', $osh->id)->update(['stock_reset_at' => now()->subHours(5)]);
        $order = $this->orderReserving($this->service()->reserve([$this->line($osh, 5)]), ['created_at' => now()->subHour()]);

        $this->assertSame([$osh->id => 5], $this->service()->release($order));
        $this->assertSame(20, $this->stock($osh));
    }

    // --- Terminal holatlar ----------------------------------------------------------------------------

    public function test_every_terminal_order_status_has_an_explicit_stock_decision(): void
    {
        // Yangi yakuniy holat (rad etildi, muddati o'tdi ...) qo'shilsa bu test sinadi — qaror qabul qilinsin.
        $decided = ['delivered' => false, 'cancelled' => true];

        $final = array_values(array_map(fn (OrderStatus $s) => $s->value, array_filter(OrderStatus::cases(), fn (OrderStatus $s) => $s->isFinal())));
        $this->assertEqualsCanonicalizing(array_keys($decided), $final);

        foreach (OrderStatus::cases() as $status) {
            $this->assertSame($decided[$status->value] ?? false, $status->releasesStock(), $status->value);
        }
    }
}
