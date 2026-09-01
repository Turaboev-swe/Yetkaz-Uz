<?php

namespace App\Services\Dispatch;

use App\Models\Order;

/**
 * Buyurtmani oshxonaga yuborish drayveri (Claude.md).
 * Har `pos_type` uchun alohida amalga oshirish; muvaffaqiyatsizlik — istisno.
 */
interface DispatchDriver
{
    public function dispatch(Order $order): DispatchResult;
}
