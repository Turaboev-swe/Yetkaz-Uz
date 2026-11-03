<?php

namespace Tests\Feature\Stock;

use App\Models\Category;
use App\Models\District;
use App\Models\Order;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * HAQIQIY parallellik: bir nechta alohida OS jarayoni (har biri o'z DB ulanishi bilan) bir vaqtda bitta mahsulotga
 * tegadi. RefreshDatabase tranzaksiyasi boshqa ulanishlarga ko'rinmaydi, shuning uchun bu sinf ma'lumotni commit qiladi
 * va oxirida o'zi tozalaydi (tests/Support/stock_parallel_child.php).
 */
class StockParallelTest extends TestCase
{
    private const BARRIER = 94_217_001;

    /** @var array<string, list<int>> */
    private array $created = ['order' => [], 'user' => [], 'product' => [], 'category' => [], 'restaurant' => [], 'district' => [], 'region' => []];

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('products') || ! Schema::hasColumn('orders', 'stock_reserved')) {
            Artisan::call('migrate', ['--force' => true]);
        }
    }

    protected function tearDown(): void
    {
        DB::select('select pg_advisory_unlock_all()');

        foreach (['order' => 'orders', 'product' => 'products', 'category' => 'categories', 'restaurant' => 'restaurants', 'user' => 'users', 'district' => 'districts', 'region' => 'regions'] as $key => $table) {
            if ($this->created[$key] !== []) {
                DB::table($table)->whereIn('id', $this->created[$key])->delete();
            }
        }

        parent::tearDown();
    }

    private function product(int $stock): Product
    {
        $restaurant = Restaurant::factory()->for($district = District::factory()->create())->create();
        $category = Category::factory()->for($restaurant)->create();
        $product = Product::factory()->for($category)->create(['name' => 'Osh', 'stock_quantity' => $stock]);

        $this->created['product'][] = $product->id;
        $this->created['category'][] = $category->id;
        $this->created['restaurant'][] = $restaurant->id;
        $this->created['district'][] = $district->id;
        $this->created['region'][] = $district->region_id;

        return $product;
    }

    /**
     * Jarayonlarni ishga tushiradi, hammasi to'siqda kutib turganda ochadi va chiqish kodlarini qaytaradi.
     *
     * @param  list<list<string>>  $commands
     * @return list<int>
     */
    private function runTogether(array $commands): array
    {
        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) config('database.connections.pgsql.host'),
            'DB_PORT' => (string) config('database.connections.pgsql.port'),
            'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
            'DB_USERNAME' => (string) config('database.connections.pgsql.username'),
            'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
            'DB_URL' => '',
        ];

        DB::select('select pg_advisory_lock(?)', [self::BARRIER]);   // to'siq yopiq

        $processes = array_map(function (array $args) use ($env): Process {
            $p = new Process([PHP_BINARY, base_path('tests/Support/stock_parallel_child.php'), ...$args], base_path(), $env, null, 60);
            $p->start();

            return $p;
        }, $commands);

        // Hammasi to'siqda (lock kutayotgan) bo'lguncha kutamiz.
        $deadline = microtime(true) + 40;
        do {
            usleep(100_000);
            $waiting = (int) DB::selectOne("select count(*) c from pg_locks where locktype = 'advisory' and not granted")->c;
            $dead = array_filter($processes, fn (Process $p) => ! $p->isRunning());
        } while ($waiting < count($processes) && $dead === [] && microtime(true) < $deadline);

        DB::select('select pg_advisory_unlock(?)', [self::BARRIER]);   // hammasi bir vaqtda boshlaydi

        $codes = [];
        foreach ($processes as $p) {
            $p->wait();
            $codes[] = (int) $p->getExitCode();
            if ($p->getExitCode() === 1) {
                $this->fail('child jarayon xato berdi: '.$p->getErrorOutput());
            }
        }

        return $codes;
    }

    public function test_two_parallel_orders_for_the_last_piece_only_one_wins(): void
    {
        $product = $this->product(1);

        $codes = $this->runTogether([
            ['reserve', (string) $product->id, '1', (string) self::BARRIER],
            ['reserve', (string) $product->id, '1', (string) self::BARRIER],
        ]);

        sort($codes);
        $this->assertSame([0, 3], $codes, 'bittasi buyurtma oladi, ikkinchisiga "qoldi" xabari');
        $this->assertSame(0, (int) DB::table('products')->where('id', $product->id)->value('stock_quantity'));
    }

    public function test_many_parallel_orders_never_oversell_and_never_go_negative(): void
    {
        $product = $this->product(3);

        $codes = $this->runTogether(array_fill(0, 7, ['reserve', (string) $product->id, '1', (string) self::BARRIER]));

        $this->assertSame(3, count(array_filter($codes, fn (int $c) => $c === 0)), '3 dona — aynan 3 ta muvaffaqiyat');
        $this->assertSame(4, count(array_filter($codes, fn (int $c) => $c === 3)));
        $this->assertSame(0, (int) DB::table('products')->where('id', $product->id)->value('stock_quantity'));
    }

    public function test_parallel_releases_of_the_same_order_return_the_stock_only_once(): void
    {
        $product = $this->product(10);
        $user = User::factory()->create();
        $order = Order::factory()->for(Restaurant::query()->findOrFail(Category::query()->findOrFail($product->category_id)->restaurant_id))->for($user)
            ->create(['stock_reserved' => [$product->id => 4]]);
        DB::table('products')->where('id', $product->id)->update(['stock_quantity' => 6]);   // 10 - 4 band qilingan
        $this->created['order'][] = $order->id;
        $this->created['user'][] = $user->id;

        $codes = $this->runTogether(array_fill(0, 4, ['release', (string) $order->id, (string) self::BARRIER]));

        $this->assertSame([0, 0, 0, 0], $codes);
        $this->assertSame(10, (int) DB::table('products')->where('id', $product->id)->value('stock_quantity'), 'faqat bir marta +4');
    }
}
