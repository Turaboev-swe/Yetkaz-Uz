<?php

namespace App\Jobs;

use App\Enums\DeliveryType;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

/**
 * Yangi buyurtma — restoran egasiga Telegram xabari.
 *
 * Manzil `restaurants.notify_chat_id` da. Bo'sh bo'lsa — jimgina o'tadi.
 * Xabarda: mijoz ismi, telefoni, @username, yetkazish manzili + lokatsiya pin.
 */
class NotifyRestaurantOfNewOrder implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public readonly int $orderId) {}

    public function handle(Nutgram $bot): void
    {
        $order = Order::query()
            ->with(['restaurant', 'user'])
            ->find($this->orderId);

        if ($order === null || blank($order->restaurant->notify_chat_id)) {
            return;
        }

        $chatId = $order->restaurant->notify_chat_id;

        try {
            $bot->sendMessage(
                text: $this->message($order),
                chat_id: $chatId,
                parse_mode: ParseMode::HTML,
            );

            $snap = $order->address_snapshot;
            if (! $order->delivery_type->isPickup() && isset($snap['lat'], $snap['lng'])) {
                $bot->sendLocation(
                    latitude: (float) $snap['lat'],
                    longitude: (float) $snap['lng'],
                    chat_id: $chatId,
                );
            }
        } catch (TelegramException $e) {
            // "chat not found" / "bot was blocked" — egasi botni ishga tushirmagan.
            // Doimiy xato: qayta urinmaymiz, restoranга ogohlantirish loglaymiz.
            if ($this->isPermanent($e)) {
                Log::warning('[order-notify] yuborilmadi — egasi botni ishga tushirmagan yoki bloklagan', [
                    'order' => $order->order_number,
                    'restaurant_id' => $order->restaurant_id,
                    'reason' => $e->getMessage(),
                ]);

                return;
            }

            throw $e; // vaqtinchalik — qayta urinsin
        }
    }

    private function isPermanent(TelegramException $e): bool
    {
        $m = strtolower($e->getMessage());

        foreach (['chat not found', 'bot was blocked', 'user is deactivated', 'bot can\'t initiate'] as $needle) {
            if (str_contains($m, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function message(Order $order): string
    {
        $e = fn (?string $v): string => e((string) $v);
        $som = fn (int $tiyin): string => number_format(intdiv($tiyin, 100), 0, '.', ' ');

        $user = $order->user;
        $lines = [];

        $lines[] = '🔔 <b>Yangi buyurtma</b> — '.$e($order->restaurant->name);
        $lines[] = '№ '.$e($order->order_number);
        $lines[] = $order->delivery_type === DeliveryType::Pickup ? '🏃 Olib ketish' : '🛵 Yetkazib berish';
        $lines[] = '';

        $lines[] = '👤 <b>'.$e($user->full_name ?: 'Ism yo‘q').'</b>';
        if ($user->phone) {
            $lines[] = '📞 '.$e($user->phone);
        }
        $lines[] = '💬 '.($user->username ? '@'.$e($user->username) : 'username yo‘q');

        $snap = $order->address_snapshot;
        if (! $order->delivery_type->isPickup() && $snap) {
            $lines[] = '';
            $lines[] = '📍 '.$e(trim(($snap['address_text'] ?? '').' · '.($snap['district'] ?? ''), ' ·'));
            $extra = array_filter([
                ($snap['entrance'] ?? null) ? 'kirish '.$snap['entrance'] : null,
                ($snap['floor'] ?? null) ? 'qavat '.$snap['floor'] : null,
                ($snap['apartment'] ?? null) ? 'xonadon '.$snap['apartment'] : null,
            ]);
            if ($extra !== []) {
                $lines[] = $e(implode(' · ', $extra));
            }
        }

        $lines[] = '';
        $lines[] = '🧾';
        foreach ($order->items as $it) {
            $lines[] = '• '.$e($it['name']).' × '.$it['qty'].' — '.$som($it['price'] * $it['qty']);
        }

        $lines[] = '';
        $lines[] = 'Taomlar: '.$som($order->subtotal).' so‘m';
        if (! $order->delivery_type->isPickup()) {
            $lines[] = 'Yetkazish: '.($order->delivery_fee > 0 ? $som($order->delivery_fee).' so‘m' : 'bepul');
        }
        $lines[] = '<b>Jami: '.$som($order->total).' so‘m</b> · naqd';

        if ($order->note) {
            $lines[] = '';
            $lines[] = '📝 '.$e($order->note);
        }

        return implode("\n", $lines);
    }
}
