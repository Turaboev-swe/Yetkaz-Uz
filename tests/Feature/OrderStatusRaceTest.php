<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Category;
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
 * HAQIQIY parallellik: admin «Bekor qilish» va oshxona «Qabul qilish» bir vaqtda, ikki alohida OS jarayoni (har biri o'z DB
 * ulanishi bilan). Ikkalasi ham buyurtmani `new` deb o'qigan — qatorni qulflagan birinchisi o'tadi, ikkinchisi qulf
 * bo'shagach holat o'zgarganini ko'rib RAD ETILADI. Natija har doim izchil: yo qabul qilingan (qoldiq band), yo bekor
 * qilingan (qoldiq qaytgan) — hech qachon «bekor qilingan, lekin keyin qabul qilingan» yoki ikki marta qaytgan qoldiq.
 *
 * RefreshDatabase tranzaksiyasi boshqa ulanishlarga ko'rinmaydi, shuning uchun ma'lumot commit qilinadi va tearDown'da
 * boshlang'ich id'lardan keyingi qatorlar o'chiriladi (tests/Support/order_race_child.php).
 */
class OrderStatusRaceTest extends TestCase
{
    private const BARRIER = 94_217_002;

    private const TABLES = ['orders', 'products', 'categories', 'addresses', 'restaurants', 'users', 'districts', 'regions'];

    /** @var array<string, int> jadval => test boshlanishidagi eng katta id */
    private array $baseline = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'stock_reserved')) {
            Artisan::call('migrate', ['--force' => true]);
        }

        foreach (self::TABLES as $table) {
            $this->baseline[$table] = (int) DB::table($table)->max('id');
        }
    }

    protected function tearDown(): void
    {
        DB::select('select pg_advisory_unlock_all()');

        foreach (self::TABLES as $table) {
            DB::table($table)->where('id', '>', $this->baseline[$table] ?? PHP_INT_MAX)->delete();
        }

        parent::tearDown();
    }

    /** @return array{0: Order, 1: Product} qoldig'i 10 dan 4 band qilingan, qabul qilinmagan buyurtma */
    private function placedOrder(): array
    {
        $restaurant = Restaurant::factory()->create(['is_open' => true]);
        $product = Product::factory()->for(Category::factory()->for($restaurant)->create())->create(['name' => 'Osh', 'stock_quantity' => 6]);
        $order = Order::factory()->for($restaurant)->for(User::factory())->create([
            'status' => OrderStatus::New,
            'stock_reserved' => [$product->id => 4],
        ]);

        return [$order, $product];
    }

    /**
     * @param  list<list<string>>  $commands
     * @return list<int> chiqish kodlari (buyruqlar tartibida)
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
            $p = new Process([PHP_BINARY, base_path('tests/Support/order_race_child.php'), ...$args], base_path(), $env, null, 60);
            $p->start();

            return $p;
        }, $commands);

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

    public function test_admin_cancel_and_kitchen_accept_at_the_same_moment_only_one_goes_through(): void
    {
        [$order, $product] = $this->placedOrder();

        $codes = $this->runTogether([
            ['accept', (string) $order->id, (string) self::BARRIER],
            ['cancel', (string) $order->id, (string) self::BARRIER],
        ]);

        sort($codes);
        $this->assertSame([0, 4], $codes, 'bittasi o\'tdi, ikkinchisi "holat o\'zgargan" bilan rad etildi');

        $order->refresh();
        $stock = (int) DB::table('products')->where('id', $product->id)->value('stock_quantity');
        $history = DB::table('order_status_history')->where('order_id', $order->id)->pluck('status')->all();

        if ($order->status === OrderStatus::Cancelled) {
            $this->assertSame(10, $stock, 'bekor qilindi — qoldiq bir marta qaytdi');
            $this->assertSame(['cancelled'], $history, 'bekor qilingan buyurtma keyin qabul qilinmagan');
            $this->assertNotNull($order->cancelled_at);
        } else {
            $this->assertSame(OrderStatus::Accepted, $order->status);
            $this->assertSame(6, $stock, 'qabul qilindi — qoldiq band bo\'lib qoladi');
            $this->assertSame(['accepted'], $history);
            $this->assertNull($order->cancelled_at);
        }
    }

    public function test_two_admins_cancelling_the_same_order_return_the_stock_once(): void
    {
        [$order, $product] = $this->placedOrder();

        $codes = $this->runTogether([
            ['cancel', (string) $order->id, (string) self::BARRIER],
            ['cancel', (string) $order->id, (string) self::BARRIER],
        ]);

        sort($codes);
        $this->assertSame([0, 4], $codes);
        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertSame(10, (int) DB::table('products')->where('id', $product->id)->value('stock_quantity'));
        $this->assertSame(1, DB::table('order_status_history')->where('order_id', $order->id)->count());
    }

    public function test_two_tablets_accepting_the_same_order_advance_it_only_once(): void
    {
        [$order] = $this->placedOrder();

        $codes = $this->runTogether([
            ['accept', (string) $order->id, (string) self::BARRIER],
            ['accept', (string) $order->id, (string) self::BARRIER],
        ]);

        sort($codes);
        $this->assertSame([0, 4], $codes, 'ikkinchi planshet buyurtmani accepted dan preparing ga o\'tkazib yubormaydi');
        $this->assertSame(OrderStatus::Accepted, $order->refresh()->status);
        $this->assertSame(['accepted'], DB::table('order_status_history')->where('order_id', $order->id)->pluck('status')->all());
    }
}
