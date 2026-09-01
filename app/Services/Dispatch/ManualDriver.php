<?php

namespace App\Services\Dispatch;

use App\Models\Order;

/**
 * `manual` — chek yo'q, buyurtma faqat oshxona panelida ko'rinadi (Claude.md).
 * Jowi/Poster/iiko ham hozircha shu yerга tushadi (integratsiya kelajakda).
 */
class ManualDriver implements DispatchDriver
{
    public function dispatch(Order $order): DispatchResult
    {
        return DispatchResult::skipped('manual', 'Chek chiqarilmaydi — faqat oshxona paneli.');
    }
}
