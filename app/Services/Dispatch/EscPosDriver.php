<?php

namespace App\Services\Dispatch;

use App\Events\PrintJobRequested;
use App\Models\Order;

/**
 * ESC/POS: chekni Reverb orqali oshxona kompyuteridagi print agentga yuboradi
 * (`restaurant.{id}.print` kanali). Broadcast xatosi — istisno (job qayta urinadi).
 */
class EscPosDriver implements DispatchDriver
{
    public function __construct(private readonly ReceiptFormatter $formatter) {}

    public function dispatch(Order $order): DispatchResult
    {
        $receipt = $this->formatter->format($order);

        // ShouldBroadcastNow — sinxron; Reverb ishlamasa shu yerda istisno tashlanadi.
        PrintJobRequested::dispatch(
            $order->id,
            $order->restaurant_id,
            $order->order_number,
            base64_encode($receipt['escpos']),
            $receipt['text'],
        );

        return DispatchResult::ok('escpos');
    }
}
