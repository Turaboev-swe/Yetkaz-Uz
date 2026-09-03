<?php

namespace App\Telegram\Support;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Ordering\OrderStatusService;
use App\Support\OrderAddress;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * Oshxona xodimiga yuboriladigan buyurtma xabari + status tugmasi.
 *
 * Xabar matni joriy statusni aks ettiradi; tugma `advance()` yuboradigan
 * keyingi bosqichni bildiradi (/kitchen bilan bir xil). Yakunlangan buyurtmада
 * tugma bo'lmaydi.
 *
 * Til: xodimда `language` ustuni yo'q — panel o'zbekcha. `__()` ga locale
 * uchinchi argument sifatida beriladi (navbat ishchisiда global locale oqib
 * ketmasin).
 */
class KitchenOrderMessage
{
    public function __construct(
        private readonly OrderStatusService $status,
        private readonly string $locale = 'uz',
    ) {}

    /** Tugma callback_data: "kadv:{order}:{joriy status}" — eskirganini aniqlash uchun. */
    public static function callbackData(Order $order): string
    {
        return "kadv:{$order->id}:{$order->status->value}";
    }

    public function text(Order $order): string
    {
        $t = fn (string $key, array $r = []): string => (string) __("messages.kitchen_bot.$key", $r, $this->locale);
        $esc = fn (?string $v): string => htmlspecialchars((string) $v, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $som = fn (int $tiyin): string => number_format(intdiv($tiyin, 100), 0, '.', ' ');
        $final = $order->status->isFinal();

        $lines = [];
        $lines[] = ($final ? $t('done') : '<b>'.$t('new_order').'</b>').' — '.$esc($order->order_number);
        $lines[] = $order->delivery_type->isPickup() ? $t('pickup') : $t('delivery');
        $lines[] = '';

        $lines[] = '👤 '.$esc($order->user?->full_name ?: '—');
        if (filled($order->user?->phone)) {
            $lines[] = '📞 '.$esc($order->user->phone);
        }

        if (! $order->delivery_type->isPickup()) {
            $addr = OrderAddress::line($order->address_snapshot);
            if ($addr !== null) {
                $extra = OrderAddress::extra($order->address_snapshot);
                $lines[] = '📍 '.$t('address').': '.$esc($extra !== null ? "$addr ($extra)" : $addr);
            }
            $map = OrderAddress::mapUrl($order->address_snapshot);
            if ($map !== null) {
                $lines[] = '🗺 <a href="'.$esc($map).'">'.$t('map').'</a>';
            }
        }

        $lines[] = '';
        foreach ($order->items as $item) {
            $lines[] = '• '.$item['qty'].'x '.$esc($item['name']);
        }

        if (filled($order->note)) {
            $lines[] = '';
            $lines[] = '📝 '.$t('note').': '.$esc($order->note);
        }

        $lines[] = '';
        $lines[] = '<b>'.$t('total').': '.$som((int) $order->total).' '.$t('som').'</b>';
        $lines[] = $t('status').': '.$esc((string) __("messages.order_status.{$order->status->value}", [], $this->locale));

        return implode("\n", $lines);
    }

    /** Keyingi amal tugmasi. Yakunlangan buyurtmада — bo'sh (tugmasiz) markup. */
    public function keyboard(Order $order): InlineKeyboardMarkup
    {
        $next = $this->status->nextStatus($order);

        if ($next === null) {
            return InlineKeyboardMarkup::make();
        }

        return InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(
                $this->buttonLabel($next, $order),
                callback_data: self::callbackData($order),
            ),
        );
    }

    private function buttonLabel(OrderStatus $next, Order $order): string
    {
        $key = match ($next) {
            OrderStatus::Accepted => 'btn_accept',
            OrderStatus::Preparing => 'btn_prepare',
            OrderStatus::OnTheWay => 'btn_on_the_way',
            OrderStatus::Delivered => $order->delivery_type->isPickup() ? 'btn_picked_up' : 'btn_delivered',
            default => 'btn_accept',
        };

        return (string) __("messages.kitchen_bot.$key", [], $this->locale);
    }
}
