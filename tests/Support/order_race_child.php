<?php

/**
 * Parallel testlar uchun alohida OS jarayoni (tests/Feature/OrderStatusRaceTest.php): buyurtma holati poygasi.
 * Test ilovasini yuklaydi, buyurtmani O'QIYDI (eskirgan nusxa bo'lib qolishi mumkin), "to'siq" (advisory lock) ochilguncha
 * kutadi, keyin OrderStatusService orqali o'tkazadi.
 *
 *   php order_race_child.php accept <orderId> <barrierKey>   oshxona "Qabul qilish" (advance)
 *   php order_race_child.php cancel <orderId> <barrierKey>   admin "Bekor qilish" (cancel, changed_by = admin:1)
 *
 * Chiqish kodi: 0 — o'tdi, 4 — holat o'zgarib bo'lgani uchun rad etildi (ValidationException), 1 — kutilmagan xato.
 */

use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Services\Ordering\OrderStatusService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $mode, $orderId, $barrier] = [...$argv, null, null, null];

// Tashqi chaqiruvlar yo'q: navbat va Reverb hodisalari soxta (faqat DB tranzaksiyasi haqiqiy).
Queue::fake();
Event::fake([OrderStatusChanged::class]);

try {
    // Panel/admin sahifasi kabi: buyurtma avval o'qiladi...
    $order = Order::withoutGlobalScopes()->findOrFail((int) $orderId);

    // ...to'siq: ota jarayon ochgunicha hamma kutadi, keyin hammasi bir vaqtda boshlaydi.
    DB::select('select pg_advisory_lock_shared(?)', [(int) $barrier]);
    DB::select('select pg_advisory_unlock_shared(?)', [(int) $barrier]);

    $service = app(OrderStatusService::class);

    // Tashqi tranzaksiya commit'gacha qatorni ushlab turadi (usleep) — to'qnashuv haqiqiy bo'lsin.
    DB::transaction(function () use ($mode, $service, $order): void {
        match ($mode) {
            'accept' => $service->advance($order, 'staff:1'),
            'cancel' => $service->cancel($order, 'Sabab', 'admin:1'),
        };
        usleep(300_000);
    });

    exit(0);
} catch (ValidationException) {
    exit(4);
} catch (Throwable $e) {
    fwrite(STDERR, $e::class.': '.$e->getMessage().PHP_EOL);
    exit(1);
}
