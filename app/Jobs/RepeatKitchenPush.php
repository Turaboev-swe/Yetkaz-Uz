<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Enums\StaffRole;
use App\Models\Order;
use App\Models\Staff;
use App\Notifications\NewKitchenOrderPushNotification;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Qabul qilinmagan buyurtma — push bildirishnoma qabul qilinguncha
 * takrorlanadi: har `kitchen.push_repeat_seconds` (60s), buyurtma
 * yaratilgandan ko'pi bilan `kitchen.push_repeat_max_minutes` (30 daq).
 * Restoran xodimlariga Telegram eslatmasi YO'Q — faqat push.
 *
 * Birinchi push NotifyKitchenStaffOfNewOrderPush'dan (yangi buyurtma);
 * bu zanjir undan keyingi intervaldan boshlanadi. Push bir xil `tag`
 * (buyurtma raqami) + `renotify` bilan — ekranda bitta bildirishnoma,
 * har safar qayta ovoz/vibratsiya.
 *
 * Har ishga tushishda:
 *   1. vaqti kelmagan bo'lsa — kutadi (sync navbatda jim to'xtaydi);
 *   2. buyurtma 'new' emas yoki maksimal muddat o'tgan — zanjir tugaydi;
 *   3. intervalni ATOMIK egallaydi: last_push_at yarim intervaldan eskiroq
 *      bo'lsagina yangilanadi. Ikkinchi (parallel) zanjir yoki qayta ishlagan
 *      job 0 qator oladi va to'xtaydi — har intervalda bitta push;
 *   4. keyingi intervalni push'dan OLDIN navbatga qo'yadi (push xatosi
 *      zanjirni uzmaydi), keyin push yuboradi.
 */
class RepeatKitchenPush implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $backoff = 5;

    /** Navbat bir necha soniya erta uyg'otsa ham ishlayversin. */
    private const EARLY_TOLERANCE_SECONDS = 5;

    public function __construct(
        public readonly int $orderId,
        public readonly int $dueAt, // unix timestamp — shu intervalning rejadagi vaqti
    ) {}

    /** Yangi buyurtmada: birinchi takror bir intervaldan keyin. */
    public static function start(Order $order): void
    {
        self::scheduleAt($order->id, ($order->created_at ?? now())->copy()->addSeconds(self::interval()));
    }

    public static function interval(): int
    {
        return (int) config('kitchen.push_repeat_seconds');
    }

    private static function scheduleAt(int $orderId, Carbon $dueAt): void
    {
        self::dispatch($orderId, $dueAt->getTimestamp())->delay($dueAt->isFuture() ? $dueAt : null);
    }

    public function handle(): void
    {
        $dueAt = Carbon::createFromTimestamp($this->dueAt);

        if (now()->addSeconds(self::EARLY_TOLERANCE_SECONDS)->lessThan($dueAt)) {
            $this->requeueIfRealQueue($dueAt);

            return;
        }

        $order = Order::withoutGlobalScopes()->find($this->orderId);

        if ($order === null || $order->status !== OrderStatus::New) {
            return; // qabul qilingan / bekor qilingan — zanjir tugadi
        }

        $deadline = $order->created_at->copy()->addMinutes((int) config('kitchen.push_repeat_max_minutes'));

        if (now()->greaterThan($deadline) || ! $this->claimInterval()) {
            return;
        }

        $next = $dueAt->copy()->addSeconds(self::interval());
        if ($next->lessThanOrEqualTo($deadline)) {
            self::scheduleAt($this->orderId, $next);
        }

        $this->push($order);
    }

    /**
     * Shu intervalni egallaydi. Oxirgi push yarim intervaldan yaqinroq bo'lsa
     * (boshqa zanjir yoki shu job qayta ishladi) — false.
     */
    private function claimInterval(): bool
    {
        $threshold = now()->subSeconds(intdiv(self::interval(), 2));

        return Order::withoutGlobalScopes()
            ->whereKey($this->orderId)
            ->where('status', OrderStatus::New->value)
            ->where(fn ($q) => $q->whereNull('last_push_at')->orWhere('last_push_at', '<=', $threshold))
            ->update(['last_push_at' => now()]) === 1;
    }

    private function push(Order $order): void
    {
        try {
            $recipients = Staff::query()
                ->where('restaurant_id', $order->restaurant_id)
                ->where('is_active', true)
                ->whereIn('role', [StaffRole::KitchenStaff->value, StaffRole::RestaurantOwner->value])
                ->whereHas('pushSubscriptions')
                ->get();

            if ($recipients->isEmpty()) {
                return;
            }

            $summary = count($order->items ?? []).' ta taom, '.Money::soms($order->total);
            $waiting = max(1, (int) $order->created_at->diffInMinutes(now()));

            Notification::send($recipients, new NewKitchenOrderPushNotification($order->order_number, $summary, $waiting));
        } catch (Throwable $e) {
            Log::warning('[kitchen-push-repeat] push yuborilmadi', [
                'order' => $order->order_number,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * `sync` navbat `delay` ni e'tiborsiz qoldiradi — u yerda jim to'xtaymiz
     * (aks holda buyurtma berilishi bilan zanjir cheksiz aylanardi).
     * Haqiqiy navbat erta uyg'otgan bo'lsa — rejadagi vaqtga qayta qo'yamiz.
     */
    private function requeueIfRealQueue(Carbon $dueAt): void
    {
        if ($this->job !== null && ! $this->job instanceof SyncJob) {
            self::dispatch($this->orderId, $this->dueAt)->delay($dueAt);
        }
    }
}
