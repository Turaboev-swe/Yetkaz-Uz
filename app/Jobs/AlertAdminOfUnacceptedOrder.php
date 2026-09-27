<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Enums\StaffRole;
use App\Models\Order;
use App\Models\Staff;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use Throwable;

/**
 * Buyurtma `kitchen.admin_alert_minutes` (7) daqiqa ichida qabul qilinmasa —
 * platforma adminiga BIR MARTALIK Telegram ogohlantirish: restoran,
 * buyurtma raqami, necha daqiqa kutyapti, restoran va mijoz telefoni
 * (admin o'zi qo'ng'iroq qila olishi uchun).
 *
 * Push zanjiridan (RepeatKitchenPush) mustaqil — yangi buyurtmada alohida
 * kechiktirilgan holda navbatga qo'yiladi.
 *
 * Bir martalik: `admin_alerted_at` atomik to'ldiriladi (faqat null va
 * buyurtma hali 'new' bo'lsa) — job qayta ishlasa ham ikkinchi xabar yo'q.
 */
class AlertAdminOfUnacceptedOrder implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    private const EARLY_TOLERANCE_SECONDS = 5;

    public function __construct(
        public readonly int $orderId,
        public readonly int $dueAt, // unix timestamp
    ) {}

    public static function schedule(Order $order): void
    {
        $dueAt = ($order->created_at ?? now())->copy()->addMinutes((int) config('kitchen.admin_alert_minutes'));

        self::dispatch($order->id, $dueAt->getTimestamp())->delay($dueAt->isFuture() ? $dueAt : null);
    }

    public function handle(Nutgram $bot): void
    {
        $dueAt = Carbon::createFromTimestamp($this->dueAt);

        if (now()->addSeconds(self::EARLY_TOLERANCE_SECONDS)->lessThan($dueAt)) {
            // sync navbat delay'ni e'tiborsiz qoldiradi — u yerda jim to'xtaymiz.
            if ($this->job !== null && ! $this->job instanceof SyncJob) {
                self::dispatch($this->orderId, $this->dueAt)->delay($dueAt);
            }

            return;
        }

        $claimed = Order::withoutGlobalScopes()
            ->whereKey($this->orderId)
            ->where('status', OrderStatus::New->value)
            ->whereNull('admin_alerted_at')
            ->update(['admin_alerted_at' => now()]) === 1;

        if (! $claimed) {
            return; // qabul/bekor qilingan yoki allaqachon yuborilgan
        }

        $order = Order::withoutGlobalScopes()->with(['restaurant', 'user'])->find($this->orderId);

        // Bir xil chat id'li bir nechta admin yozuvi — bitta xabar.
        $chatIds = Staff::query()
            ->where('role', StaffRole::PlatformAdmin->value)
            ->where('is_active', true)
            ->whereNotNull('telegram_chat_id')
            ->pluck('telegram_chat_id')
            ->unique();

        if ($chatIds->isEmpty()) {
            Log::warning('[admin-alert] platforma admini topilmadi (telegram_chat_id yo\'q) — ogohlantirish yuborilmadi', [
                'order' => $order->order_number,
            ]);

            return;
        }

        $text = (string) __('messages.kitchen_bot.escalation_admin', [
            'restaurant' => e($order->restaurant?->name ?? '—'),
            'order' => e($order->order_number),
            'minutes' => max(1, (int) $order->created_at->diffInMinutes(now())),
            'restaurant_phone' => e($order->restaurant?->phone ?: '—'),
            'customer_phone' => e($order->user?->phone ?: '—'),
        ], 'uz');

        foreach ($chatIds as $chatId) {
            try {
                $bot->sendMessage(text: $text, chat_id: (int) $chatId, parse_mode: ParseMode::HTML);
            } catch (Throwable $e) {
                Log::warning('[admin-alert] adminga yuborilmadi', [
                    'order' => $order->order_number,
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
