<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Enums\StaffRole;
use App\Models\Order;
use App\Models\Staff;
use App\Notifications\NewKitchenOrderPushNotification;
use App\Support\Money;
use App\Telegram\Support\KitchenOrderMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/**
 * Qabul qilinmagan buyurtma — bosqichma-bosqich eslatma. Hech bir buyurtma
 * jimgina yo'qolmasin.
 *
 * Vaqtlar `config('kitchen.escalation_minutes')` (standart 2, 4, 7 daqiqa,
 * buyurtma yaratilgan paytdan):
 *   1 — push + Telegram eslatma barcha faol xodimlarga ("Qabul qilish" tugmasi bilan)
 *   2 — push + Telegram; restoran egasiga alohida, urg'uli matn
 *   3 (oxirgi) — platforma adminiga Telegram: restoran, buyurtma, necha
 *       daqiqa, restoran va mijoz telefoni. Shu bilan eslatmalar tugaydi.
 *
 * Har ishga tushishda bosqich ATOMIK egallanadi:
 *   UPDATE orders SET escalation_stage = N WHERE status = 'new' AND escalation_stage < N
 * Buyurtma qabul/bekor qilingan bo'lsa yoki bosqich allaqachon bajarilgan
 * bo'lsa (job qayta ishlagan) — 0 qator, hech narsa yuborilmaydi.
 *
 * Keyingi bosqich yuborishdan OLDIN navbatga qo'yiladi — Telegram/push
 * xatosi zanjirni uzmaydi. Har kanal xatosi alohida ushlanadi.
 */
class EscalateUnacceptedOrder implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Egallashdan oldin (masalan DB uzilishi) yiqilsa qayta urinadi; egallangandan keyin qayta urinish hech narsa yubormaydi. */
    public int $tries = 3;

    public int $backoff = 10;

    private const LOCALE = 'uz';

    public function __construct(
        public readonly int $orderId,
        public readonly int $stage,
    ) {}

    /** Bosqichni buyurtma yaratilgan vaqtga nisbatan kechiktirib navbatga qo'yadi. */
    public static function schedule(Order $order, int $stage = 1): void
    {
        $minutes = self::stageMinutes($stage);

        if ($minutes === null) {
            return;
        }

        $runAt = ($order->created_at ?? now())->copy()->addMinutes($minutes);

        self::dispatch($order->id, $stage)->delay($runAt->isFuture() ? $runAt : null);
    }

    public static function lastStage(): int
    {
        return count(config('kitchen.escalation_minutes'));
    }

    public static function stageMinutes(int $stage): ?int
    {
        return config('kitchen.escalation_minutes')[$stage - 1] ?? null;
    }

    public function handle(Nutgram $bot, KitchenOrderMessage $messages): void
    {
        if (! $this->isDue()) {
            return;
        }

        if (! $this->claimStage()) {
            return;
        }

        $order = Order::withoutGlobalScopes()->with(['user', 'restaurant'])->find($this->orderId);

        if ($order === null) {
            return;
        }

        if ($this->stage < self::lastStage()) {
            self::schedule($order, $this->stage + 1);
        }

        $minutes = max((int) self::stageMinutes($this->stage), (int) $order->created_at->diffInMinutes(now()));

        if ($this->stage === self::lastStage()) {
            $this->notifyAdmins($bot, $order, $minutes);

            return;
        }

        $this->pushStaff($order, $minutes);
        $this->telegramStaff($bot, $messages, $order, $minutes);
    }

    /**
     * Bosqich vaqti keldimi (30s tolerans — soat farqi uchun).
     *
     * Erta: `sync` navbat `delay` ni e'tiborsiz qoldiradi — buyurtma berilishi
     * bilan barcha bosqichlar ketma-ket ishlab ketmasin, jim to'xtaydi.
     * Haqiqiy navbatda (Redis) erta uyg'ongan bo'lsa — kerakli vaqtga qayta qo'yiladi.
     */
    private function isDue(): bool
    {
        $createdAt = Order::withoutGlobalScopes()->whereKey($this->orderId)->value('created_at');
        $minutes = self::stageMinutes($this->stage);

        if ($createdAt === null || $minutes === null) {
            return false;
        }

        $runAt = Carbon::parse($createdAt)->addMinutes($minutes);

        if (now()->addSeconds(30)->greaterThanOrEqualTo($runAt)) {
            return true;
        }

        if ($this->job !== null && ! $this->job instanceof SyncJob) {
            self::dispatch($this->orderId, $this->stage)->delay($runAt);
        }

        return false;
    }

    /** Bosqichni atomik egallaydi: faqat buyurtma hali 'new' va bu bosqich bajarilmagan bo'lsa. */
    private function claimStage(): bool
    {
        return Order::withoutGlobalScopes()
            ->whereKey($this->orderId)
            ->where('status', OrderStatus::New->value)
            ->where('escalation_stage', '<', $this->stage)
            ->update(['escalation_stage' => $this->stage]) === 1;
    }

    private function pushStaff(Order $order, int $minutes): void
    {
        try {
            $recipients = $this->restaurantStaff($order)->whereHas('pushSubscriptions')->get();

            if ($recipients->isEmpty()) {
                return;
            }

            $summary = count($order->items ?? []).' ta taom, '.Money::soms($order->total);

            Notification::send($recipients, new NewKitchenOrderPushNotification($order->order_number, $summary, $minutes));
        } catch (Throwable $e) {
            $this->logFailure('push', $order, $e);
        }
    }

    private function telegramStaff(Nutgram $bot, KitchenOrderMessage $messages, Order $order, int $minutes): void
    {
        $staff = $this->restaurantStaff($order)->whereNotNull('telegram_chat_id')->get();
        $keyboard = $messages->keyboard($order); // "▶️ Qabul qilish" — kadv: oqimi bilan bir xil
        $args = ['order' => e($order->order_number), 'minutes' => $minutes];

        $reminder = $this->t('escalation_reminder', $args);
        // 2-bosqichdan boshlab egasi alohida, urg'uli matn oladi.
        $ownerText = $this->stage >= 2 ? $this->t('escalation_owner', $args) : $reminder;

        foreach ($staff as $member) {
            $text = $member->role === StaffRole::RestaurantOwner ? $ownerText : $reminder;
            $this->send($bot, $order, (int) $member->telegram_chat_id, $text, $keyboard);
        }
    }

    private function notifyAdmins(Nutgram $bot, Order $order, int $minutes): void
    {
        $admins = Staff::query()
            ->where('role', StaffRole::PlatformAdmin->value)
            ->where('is_active', true)
            ->whereNotNull('telegram_chat_id')
            ->pluck('telegram_chat_id');

        if ($admins->isEmpty()) {
            Log::warning('[escalation] platforma admini topilmadi (telegram_chat_id yo\'q) — xabar yuborilmadi', [
                'order' => $order->order_number,
            ]);

            return;
        }

        $text = $this->t('escalation_admin', [
            'restaurant' => e($order->restaurant?->name ?? '—'),
            'order' => e($order->order_number),
            'minutes' => $minutes,
            'restaurant_phone' => e($order->restaurant?->phone ?: '—'),
            'customer_phone' => e($order->user?->phone ?: '—'),
        ]);

        foreach ($admins as $chatId) {
            $this->send($bot, $order, (int) $chatId, $text);
        }
    }

    /** @return Builder<Staff> */
    private function restaurantStaff(Order $order)
    {
        return Staff::query()
            ->where('restaurant_id', $order->restaurant_id)
            ->where('is_active', true)
            ->whereIn('role', [StaffRole::KitchenStaff->value, StaffRole::RestaurantOwner->value]);
    }

    private function send(Nutgram $bot, Order $order, int $chatId, string $text, ?InlineKeyboardMarkup $keyboard = null): void
    {
        try {
            $bot->sendMessage(
                text: $text,
                chat_id: $chatId,
                parse_mode: ParseMode::HTML,
                reply_markup: $keyboard,
            );
        } catch (Throwable $e) {
            // Bitta xodim botni bloklagan — qolganlarga baribir yuboriladi.
            $this->logFailure('telegram', $order, $e, ['chat_id' => $chatId]);
        }
    }

    /** @param  array<string, mixed>  $args */
    private function t(string $key, array $args): string
    {
        return (string) __("messages.kitchen_bot.{$key}", $args, self::LOCALE);
    }

    /** @param  array<string, mixed>  $extra */
    private function logFailure(string $channel, Order $order, Throwable $e, array $extra = []): void
    {
        Log::warning("[escalation] {$channel} yuborilmadi", [
            'order' => $order->order_number,
            'stage' => $this->stage,
            'error' => $e->getMessage(),
        ] + $extra);
    }
}
