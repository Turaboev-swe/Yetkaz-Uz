<?php

namespace App\Services\Dispatch;

use App\Models\Order;

/**
 * `browser` — chekni oshxona paneli (/kitchen) brauzer orqali Windows printeriga chiqaradi ("Qabul qilish"
 * bosilganda). Server tomonda yuboriladigan narsa yo'q: broadcast ham, agent ham ishtirok etmaydi.
 */
class BrowserDriver implements DispatchDriver
{
    public function dispatch(Order $order): DispatchResult
    {
        return DispatchResult::skipped('browser', 'Chek brauzer orqali, oshxona panelidan chiqariladi.');
    }
}
