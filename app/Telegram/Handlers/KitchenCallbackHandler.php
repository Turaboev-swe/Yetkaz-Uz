<?php

namespace App\Telegram\Handlers;

use App\Models\Order;
use App\Models\Staff;
use App\Services\Ordering\OrderStatusService;
use App\Telegram\Support\KitchenOrderMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

/**
 * `kadv:{orderId}:{expected}` — oshxona xodimi buyurtma statusini bir bosqich
 * oldinga suradi (/kitchen `advance()` bilan bir xil).
 *
 * `expected` — tugma yaratilgandagi status. Boshqa xodim allaqachon o'zgartirgan
 * bo'lsa mos kelmaydi -> hech narsa qilinmaydi, xabar joriy holatga yangilanadi.
 * Bir vaqtda bosishга qarshi: buyurtma qatori `lockForUpdate` bilan qulflanadi.
 */
class KitchenCallbackHandler
{
    public function __construct(
        private readonly OrderStatusService $status,
        private readonly KitchenOrderMessage $message,
    ) {}

    public function __invoke(Nutgram $bot, string $orderId, string $expected): void
    {
        $staff = Staff::query()
            ->where('telegram_chat_id', $bot->userId())
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if ($staff === null || ! $staff->canManageKitchen()) {
            $bot->answerCallbackQuery(text: __('messages.kitchen_bot.cb_no_access', [], 'uz'), show_alert: true);

            return;
        }

        $outcome = DB::transaction(function () use ($orderId, $expected, $staff): array {
            $order = Order::withoutGlobalScopes()->lockForUpdate()->find((int) $orderId);

            if ($order === null || $order->restaurant_id !== $staff->restaurant_id) {
                return ['type' => 'forbidden', 'order' => null];
            }

            if ($order->status->value !== $expected) {
                return ['type' => 'stale', 'order' => $order];
            }

            try {
                $this->status->advance($order, "staff:{$staff->id}");

                return ['type' => 'advanced', 'order' => $order];
            } catch (ValidationException) {
                return ['type' => 'final', 'order' => $order];
            }
        });

        if ($outcome['type'] === 'forbidden') {
            $bot->answerCallbackQuery(text: __('messages.kitchen_bot.cb_no_access', [], 'uz'), show_alert: true);

            return;
        }

        $toast = match ($outcome['type']) {
            'stale' => __('messages.kitchen_bot.cb_stale', [], 'uz'),
            'final' => __('messages.kitchen_bot.cb_final', [], 'uz'),
            default => null,
        };
        $bot->answerCallbackQuery(text: $toast);

        $this->refresh($bot, $outcome['order']);
    }

    /** Bosilgan xabarni joriy buyurtma holatiga yangilaydi (tugma ham). */
    private function refresh(Nutgram $bot, Order $order): void
    {
        $order->loadMissing('user');

        try {
            $bot->editMessageText(
                text: $this->message->text($order),
                parse_mode: ParseMode::HTML,
                reply_markup: $this->message->keyboard($order),
            );
        } catch (TelegramException $e) {
            // "message is not modified" / juda eski xabar — muhim emas.
            Log::info('[kitchen-cb] editMessageText o\'tkazib yuborildi', [
                'order' => $order->order_number,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
