<?php

namespace App\Jobs;

use App\Events\OrderDispatchFailed;
use App\Models\Order;
use App\Services\Dispatch\OrderDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Buyurtmani oshxonaga yuboradi (chek printeri / POS). Buyurtma yaratilishi bilan
 * navbatga qo'yiladi — HTTP javobi kutilmaydi.
 *
 * 3 urinish, exponential backoff. Hammasi muvaffaqiyatsiz bo'lsa (masalan Reverb
 * uzilgan) — `orders.dispatch_failed_at` to'ldiriladi va oshxona panelida
 * "Chek chiqmadi" ogohlantirishi chiqadi.
 */
class DispatchOrderJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function __construct(public readonly int $orderId) {}

    public function handle(OrderDispatcher $dispatcher): void
    {
        $order = Order::withoutGlobalScopes()->with('restaurant')->find($this->orderId);

        if ($order === null || $order->printed_at !== null) {
            return;
        }

        $result = $dispatcher->dispatch($order);

        Log::info('[dispatch] yuborildi', [
            'order' => $order->order_number,
            'driver' => $result->driver,
            'message' => $result->message,
        ]);
    }

    public function failed(Throwable $e): void
    {
        $order = Order::withoutGlobalScopes()->find($this->orderId);

        if ($order === null) {
            return;
        }

        $order->forceFill(['dispatch_failed_at' => now()])->save();

        OrderDispatchFailed::dispatch($order->id, $order->restaurant_id, $order->order_number);

        Log::error('[dispatch] chek chiqmadi (3 urinishдан keyin)', [
            'order' => $order->order_number,
            'restaurant_id' => $order->restaurant_id,
            'error' => $e->getMessage(),
        ]);
    }
}
