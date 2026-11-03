<?php

/**
 * Parallel testlar uchun alohida OS jarayoni (tests/Feature/Stock/StockParallelTest.php). Test ilovasini yuklab,
 * "to'siq" (advisory lock) ochilguncha kutadi, keyin buyurtma tranzaksiyasidagidek qoldiqni band qiladi/qaytaradi.
 *
 *   php stock_parallel_child.php reserve <productId> <qty> <barrierKey>
 *   php stock_parallel_child.php release <orderId> <barrierKey>
 *
 * Chiqish kodi: 0 — bajarildi, 3 — qoldiq yetmadi, 1 — kutilmagan xato.
 */

use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Services\Stock\StockService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $mode] = $argv;

try {
    DB::transaction(function () use ($mode, $argv): void {
        // To'siq: ota jarayon exclusive lock'ni ochgunicha hamma kutadi — keyin hammasi bir vaqtda boshlaydi.
        $barrier = (int) ($mode === 'reserve' ? $argv[4] : $argv[3]);
        DB::select('select pg_advisory_xact_lock_shared(?)', [$barrier]);

        if ($mode === 'reserve') {
            app(StockService::class)->reserve([['product_id' => (int) $argv[2], 'name' => 'Osh', 'qty' => (int) $argv[3]]]);
        } else {
            app(StockService::class)->release(Order::withoutGlobalScopes()->findOrFail((int) $argv[2]));
        }

        usleep(250_000);   // commit'dan oldin biroz ushlab turamiz — to'qnashuv haqiqiy bo'lsin
    });
    exit(0);
} catch (InsufficientStockException) {
    exit(3);
} catch (Throwable $e) {
    fwrite(STDERR, $e::class.': '.$e->getMessage().PHP_EOL);
    exit(1);
}
