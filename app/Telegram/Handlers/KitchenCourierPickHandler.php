<?php

namespace App\Telegram\Handlers;

use App\Enums\CourierType;
use App\Models\Order;
use App\Models\Staff;
use App\Services\Ordering\OrderStatusService;
use App\Telegram\Handlers\Concerns\ResolvesKitchenStaff;
use App\Telegram\Support\KitchenOrderMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;

/**
 * `kcourierpick:{orderId}:{expected}:{staffId}` — xodim tanlandi (yoki
 * "Kuryersiz davom etish", staffId=0). Buyurtmani "Yo'lga chiqdi"ga o'tkazadi.
 */
class KitchenCourierPickHandler
{
    use ResolvesKitchenStaff;

    public function __construct(
        private readonly OrderStatusService $status,
        private readonly KitchenOrderMessage $message,
    ) {}

    public function __invoke(Nutgram $bot, string $orderId, string $expected, string $staffId): void
    {
        $t = fn (string $k): string => (string) __("messages.kitchen_bot.$k", [], 'uz');

        $staff = $this->kitchenStaff($bot);
        if ($staff === null) {
            $bot->answerCallbackQuery(text: $t('cb_no_access'), show_alert: true);

            return;
        }

        $order = Order::withoutGlobalScopes()->find((int) $orderId);
        if ($order === null || $order->restaurant_id !== $staff->restaurant_id) {
            $bot->answerCallbackQuery(text: $t('cb_no_access'), show_alert: true);

            return;
        }

        if ($order->status->value !== $expected) {
            $bot->answerCallbackQuery(text: $t('cb_stale'));

            return;
        }

        $fill = ['courier_type' => CourierType::OwnStaff->value];
        $courierId = (int) $staffId;
        if ($courierId > 0) {
            $courier = Staff::query()->where('restaurant_id', $order->restaurant_id)->find($courierId);
            if ($courier !== null) {
                $fill += [
                    'courier_staff_id' => $courier->id,
                    'courier_name' => $courier->name,
                    'courier_phone' => $courier->phone,
                ];
            }
        }

        try {
            $this->status->advance($order, "kitchen:{$staff->id}", $fill);
        } catch (ValidationException) {
            $bot->answerCallbackQuery(text: $t('cb_final'), show_alert: true);

            return;
        }

        $bot->answerCallbackQuery(text: $t('btn_on_the_way'));
        $this->showDeliveredButton($bot, $order);
    }

    /**
     * Xodim tanlash xabarini "Yo'lga chiqdi" + "✅ Yetkazildi" tugmasi bilan
     * almashtiradi (kadv: oqimidagi refresh() bilan bir xil klaviatura).
     * Tahrirlab bo'lmasa — tugma yo'qolmasin, yangi xabar sifatida yuboriladi.
     */
    private function showDeliveredButton(Nutgram $bot, Order $order): void
    {
        $text = $this->message->courierDispatchedText($order);
        $keyboard = $this->message->keyboard($order);

        try {
            $bot->editMessageText(text: $text, reply_markup: $keyboard);
        } catch (TelegramException $e) {
            Log::info('[kitchen-courier] editMessageText o\'tkazib yuborildi, yangi xabar yuboriladi', [
                'order' => $order->order_number,
                'reason' => $e->getMessage(),
            ]);
            $bot->sendMessage(text: $text, reply_markup: $keyboard);
        }
    }
}
