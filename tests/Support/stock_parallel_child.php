<?php

/**
 * Parallel testlar uchun alohida OS jarayoni (tests/Feature/Stock/StockParallelTest.php). Test ilovasini yuklab,
 * "to'siq" (advisory lock) ochilguncha kutadi, keyin buyurtma tranzaksiyasidagidek ishlaydi.
 *
 *   php stock_parallel_child.php reserve <productId> <qty> <barrierKey>
 *   php stock_parallel_child.php release <orderId> <barrierKey>
 *   php stock_parallel_child.php place   <userId> <restaurantId> <productId> <barrierKey>   (haqiqiy OrderService::place)
 *
 * Chiqish kodi: 0 — bajarildi, 3 — qoldiq yetmadi, 1 — kutilmagan xato.
 */

use App\Events\OrderPlaced;
use App\Events\OrderStatusChanged;
use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Models\User;
use App\Services\Ordering\OrderService;
use App\Services\Stock\StockService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1];
$barrier = (int) match ($mode) {
    'reserve' => $argv[4],
    'release' => $argv[3],
    'place' => $argv[5],
};

if ($mode === 'place') {
    // Tashqi chaqiruvlar yo'q: navbat va Reverb hodisalari soxta (faqat DB tranzaksiyasi haqiqiy).
    Queue::fake();
    Event::fake([OrderPlaced::class, OrderStatusChanged::class]);
}

try {
    // To'siq: ota jarayon exclusive lock'ni ochgunicha hamma kutadi — keyin hammasi bir vaqtda boshlaydi.
    DB::select('select pg_advisory_lock_shared(?)', [$barrier]);
    DB::select('select pg_advisory_unlock_shared(?)', [$barrier]);

    match ($mode) {
        'reserve' => DB::transaction(function () use ($argv): void {
            app(StockService::class)->reserve([['product_id' => (int) $argv[2], 'name' => 'Osh', 'qty' => (int) $argv[3]]]);
            usleep(250_000);   // commit'dan oldin biroz ushlab turamiz — to'qnashuv haqiqiy bo'lsin
        }),
        'release' => DB::transaction(function () use ($argv): void {
            app(StockService::class)->release(Order::withoutGlobalScopes()->findOrFail((int) $argv[2]));
            usleep(250_000);
        }),
        'place' => app(OrderService::class)->place(User::findOrFail((int) $argv[2]), [
            'restaurant_id' => (int) $argv[3],
            'delivery_type' => 'pickup',
            'items' => [['product_id' => (int) $argv[4], 'qty' => 1]],
        ]),
    };
    exit(0);
} catch (InsufficientStockException) {
    exit(3);
} catch (Throwable $e) {
    fwrite(STDERR, $e::class.': '.$e->getMessage().PHP_EOL);
    exit(1);
}
