<?php

namespace App\Telegram\Conversations;

use App\Enums\CourierType;
use App\Models\Order;
use App\Services\Ordering\OrderStatusService;
use App\Support\Phone;
use App\Telegram\Handlers\Concerns\ResolvesKitchenStaff;
use App\Telegram\Support\KitchenOrderMessage;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

/**
 * "🚕 Royal Taxi orqali" tanlanганda — haydovchi telefon raqamini matn bilan
 * so'raydi (holat Redis'да, KitchenCancelReasonConversation bilan bir xil
 * mexanizm). Bu — STAFF kiritadigan operatsion ma'lumot, mijoz o'z raqamini
 * kiritayotgani emas, shuning uchun erkin matn qabul qilinadi (faqat
 * +998 formati tekshiriladi).
 */
class KitchenCourierPhoneConversation extends Conversation
{
    use ResolvesKitchenStaff;

    public ?int $orderId = null;

    public ?string $expected = null;

    public function start(Nutgram $bot, ?int $orderId = null, ?string $expected = null): void
    {
        $this->orderId ??= $orderId;
        $this->expected ??= $expected;

        $bot->sendMessage(__('messages.kitchen_bot.courier_ask_phone', [], 'uz'));
        $this->next('handlePhone');
    }

    public function handlePhone(Nutgram $bot): void
    {
        $raw = trim((string) $bot->message()?->text);

        if ($raw === '' || str_starts_with($raw, '/')) {
            $bot->sendMessage(__('messages.kitchen_bot.courier_ask_phone', [], 'uz'));
            $this->next('handlePhone');

            return;
        }

        $phone = Phone::normalizeUzbek($raw);
        if ($phone === null) {
            $bot->sendMessage(__('messages.kitchen_bot.courier_invalid_phone', [], 'uz'));
            $this->next('handlePhone');

            return;
        }

        $staff = $this->kitchenStaff($bot);
        $order = Order::withoutGlobalScopes()->find($this->orderId);

        if ($staff !== null
            && $order !== null
            && $order->restaurant_id === $staff->restaurant_id
            && $order->status->value === $this->expected) {
            try {
                app(OrderStatusService::class)->advance($order, "kitchen:{$staff->id}", [
                    'courier_type' => CourierType::Taxi->value,
                    'courier_name' => 'Royal Taxi',
                    'courier_phone' => $phone,
                ]);
                // Telefon — xodim yozgan matn xabari, uni tahrirlab bo'lmaydi: "Yetkazildi"
                // tugmasi (kadv: oqimi bilan bir xil klaviatura) yangi xabarda chiqadi.
                $message = app(KitchenOrderMessage::class);
                $bot->sendMessage(
                    text: $message->courierDispatchedText($order),
                    reply_markup: $message->keyboard($order),
                );
            } catch (ValidationException) {
                $bot->sendMessage(__('messages.kitchen_bot.cb_final', [], 'uz'));
            }
        } elseif ($order !== null) {
            $bot->sendMessage(__('messages.kitchen_bot.cb_stale', [], 'uz'));
        }

        $this->end();
    }
}
