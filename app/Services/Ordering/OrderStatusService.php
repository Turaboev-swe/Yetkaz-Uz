<?php

namespace App\Services\Ordering;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Jobs\NotifyCustomerOfStatusChange;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Buyurtma statusini bir qadam oldinga suradi (oshxona paneli).
 *
 *   yetkazish:  new -> accepted -> preparing -> on_the_way -> delivered
 *   olib ketish: new -> accepted -> preparing -> delivered ("Mijoz oldi")
 *
 * Har o'zgarishda: tarix yozuvi, timestamp, Reverb hodisasi, mijozga bot xabari.
 */
class OrderStatusService
{
    /**
     * @param  array<string, mixed>  $fill  Status o'zgarishidan OLDIN buyurtmaга
     *                                      yoziladigan maydonlar (hozircha kuryer ma'lumoti).
     */
    public function advance(Order $order, string $changedBy, array $fill = []): Order
    {
        $next = $this->nextStatus($order);

        if ($next === null) {
            throw ValidationException::withMessages(['status' => 'Bu buyurtma allaqachon yakunlangan.']);
        }

        $fill = array_intersect_key($fill, array_flip(['courier_name', 'courier_phone']));
        if ($fill !== []) {
            $order->fill($fill);
        }

        return $this->transition($order, $next, $changedBy);
    }

    /**
     * `advance()` buyurtmani qaysi statusга o'tkazadi — yakunланган bo'lsa null.
     * Tekshiruv EMAS, faqat oldinга bitta qadam (olib ketishда on_the_way tashlanadi).
     * Bot tugmasi matni shu asosда tanlanadi (/kitchen bilan bir xil xatti-harakat).
     */
    public function nextStatus(Order $order): ?OrderStatus
    {
        return $order->delivery_type->isPickup() && $order->status === OrderStatus::Preparing
            ? OrderStatus::Delivered
            : $order->status->next();
    }

    public function transition(Order $order, OrderStatus $to, string $changedBy): Order
    {
        DB::transaction(function () use ($order, $to, $changedBy) {
            $order->status = $to;

            match ($to) {
                OrderStatus::OnTheWay => $order->dispatched_at = now(),
                OrderStatus::Delivered => $order->delivered_at = now(),
                OrderStatus::Cancelled => $order->cancelled_at = now(),
                default => null,
            };

            $order->save();

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'status' => $to->value,
                'changed_by' => $changedBy,
                'changed_at' => now(),
            ]);
        });

        OrderStatusChanged::dispatch($order->id, $order->restaurant_id, $to);
        NotifyCustomerOfStatusChange::dispatch($order->id);

        return $order;
    }
}
