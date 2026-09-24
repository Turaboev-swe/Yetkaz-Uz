<?php

namespace App\Services\Ordering;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Jobs\NotifyCustomerOfCancellation;
use App\Jobs\NotifyCustomerOfStatusChange;
use App\Jobs\RequestOrderRating;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Buyurtma statusini boshqaradi (oshxona paneli + bot).
 *
 *   yetkazish:  new -> accepted -> preparing -> on_the_way -> delivered
 *   olib ketish: new -> accepted -> preparing -> delivered ("Mijoz oldi")
 *   bekor qilish: accepted | preparing -> cancelled  (faqat shu ikki holatdan)
 *
 * Har o'zgarishда: tarix yozuvi, timestamp, Reverb hodisasi, mijozга bot xabari.
 * `/kitchen` va bot ikkalasi HAM shu servisni chaqiradi — mantiq takrorlanmaydi.
 */
class OrderStatusService
{
    /** Bekor qilish mumkin bo'lgan holatlar. */
    private const CANCELLABLE = [OrderStatus::Accepted, OrderStatus::Preparing];

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

        $fill = array_intersect_key($fill, array_flip(['courier_name', 'courier_phone', 'courier_staff_id', 'courier_type']));
        if ($fill !== []) {
            $order->fill($fill);
        }

        return $this->transition($order, $next, $changedBy);
    }

    /**
     * Buyurtmani bekor qiladi. Faqat `accepted` / `preparing` holatidа —
     * `on_the_way` / `delivered` dан bekor qilib bo'lmaydi.
     *
     * TO'LOV: hozircha faqat naqd pul — qaytarish (refund) shart emas. Onlayn
     * to'lov (Payme/Click) qo'shilса, SHU YERDA to'lovni qaytarish mantiqи
     * qo'shilishi kerak (payment_status = refunded, provayder API chaqiruvi).
     */
    public function cancel(Order $order, string $reason, string $changedBy): Order
    {
        $reason = trim($reason);

        if (! in_array($order->status, self::CANCELLABLE, true)) {
            throw ValidationException::withMessages([
                'status' => __('messages.kitchen_bot.cannot_cancel'),
            ]);
        }

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => __('messages.kitchen_bot.cancel_reason_required'),
            ]);
        }

        $order->cancellation_reason = mb_substr($reason, 0, 500);
        $this->applyTransition($order, OrderStatus::Cancelled, $changedBy);

        NotifyCustomerOfCancellation::dispatch($order->id);

        return $order;
    }

    public function canCancel(Order $order): bool
    {
        return in_array($order->status, self::CANCELLABLE, true);
    }

    /**
     * `advance()` buyurtmani qaysi statusга o'tkazadi — yakunланган bo'lsa null.
     * Tekshiruv EMAS, faqat oldinга bitta qadam (olib ketishда on_the_way tashlanadi).
     */
    public function nextStatus(Order $order): ?OrderStatus
    {
        return $order->delivery_type->isPickup() && $order->status === OrderStatus::Preparing
            ? OrderStatus::Delivered
            : $order->status->next();
    }

    public function transition(Order $order, OrderStatus $to, string $changedBy): Order
    {
        $this->applyTransition($order, $to, $changedBy);

        NotifyCustomerOfStatusChange::dispatch($order->id);

        // Yetkazilgach (yoki mijoz olib ketgach) — "yetkazildi" xabari bilan bir
        // vaqtда, kechikishsiz baho so'rovi.
        if ($to === OrderStatus::Delivered) {
            RequestOrderRating::dispatch($order->id);
        }

        return $order;
    }

    /** Statusni yozadi: DB (status + timestamp), tarix, Reverb hodisasi. */
    private function applyTransition(Order $order, OrderStatus $to, string $changedBy): void
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
    }
}
