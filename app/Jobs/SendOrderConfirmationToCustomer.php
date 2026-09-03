<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Eta\EtaEstimate;
use App\Support\OrderAddress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use Throwable;

/**
 * Buyurtma yaratilishi bilan mijozga bot orqali chek ko'rinishidagi tasdiq xabari.
 *
 * Bu xabar status-o'zgarish xabarlaridan (NotifyCustomerOfStatusChange) ALOHIDA —
 * ularni almashtirmaydi. Ketma-ketlik: chek (darhol) → qabul qilindi → tayyorlanmoqda
 * → yo'lga chiqdi → yetkazildi.
 *
 * Til: xabar mijoz tilida (users.language). `app()->setLocale()` ISHLATILMAYDI —
 * navbat ishchisi uzoq yashaydi va global locale keyingi job'ga oqib ketardi
 * (9f0299b — "til xatosi"). O'rniga har __() ga locale uchinchi argument sifatida
 * beriladi.
 */
class SendOrderConfirmationToCustomer implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Xabar muhim emas (status xabarlari baribir keladi) — 2 urinish, keyin log. */
    public int $tries = 2;

    public int $backoff = 20;

    /** Chek bloki kengligi (monospace <pre>) — telefon ekraniga sig'adigan. */
    private const WIDTH = 28;

    public function __construct(public readonly int $orderId) {}

    public function handle(Nutgram $bot): void
    {
        $order = Order::withoutGlobalScopes()
            ->with(['user', 'restaurant.district'])
            ->find($this->orderId);

        if ($order === null || blank($order->user?->telegram_id)) {
            return;
        }

        try {
            $bot->sendMessage(
                text: $this->receipt($order),
                chat_id: $order->user->telegram_id,
                parse_mode: ParseMode::HTML,
            );
        } catch (TelegramException $e) {
            $m = strtolower($e->getMessage());
            if (str_contains($m, 'chat not found') || str_contains($m, 'bot was blocked') || str_contains($m, 'deactivated')) {
                Log::info('[order-confirm] mijozga yuborilmadi', [
                    'order' => $order->order_number,
                    'reason' => $e->getMessage(),
                ]);

                return;
            }
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $order = Order::withoutGlobalScopes()->find($this->orderId);

        Log::error('[order-confirm] chek xabari yuborilmadi (2 urinishdan keyin)', [
            'order' => $order?->order_number ?? $this->orderId,
            'error' => $e->getMessage(),
        ]);
    }

    private function receipt(Order $order): string
    {
        $locale = in_array($order->user->language, ['uz', 'ru'], true) ? $order->user->language : 'uz';
        $t = fn (string $key, array $replace = []): string => (string) __("messages.order_confirmation.$key", $replace, $locale);
        $som = fn (int $tiyin): string => number_format(intdiv($tiyin, 100), 0, '.', ' ');
        // Telegram HTML rejimida faqat < > & maxsus — tirnoqlar (') matnda tabiiy qoladi.
        $esc = fn (?string $v): string => htmlspecialchars((string) $v, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $pickup = $order->delivery_type->isPickup();

        $out = [];
        $out[] = '🧾 <b>'.$esc($t('title', ['n' => $order->order_number])).'</b>';
        $out[] = '';
        $out[] = $esc($order->restaurant->name);
        $out[] = '';

        // --- Chek bloki (monospace) ---
        $sep = str_repeat('─', self::WIDTH);
        $rows = [$sep];
        foreach ($order->items as $item) {
            $rows[] = $this->leader(
                $item['qty'].'x '.$item['name'],
                $som((int) $item['price'] * (int) $item['qty']),
            );
        }
        $rows[] = $sep;
        $rows[] = $this->leader($t('subtotal'), $som((int) $order->subtotal));
        if (! $pickup) {
            $rows[] = $this->leader(
                $t('delivery'),
                $order->delivery_fee > 0 ? $som((int) $order->delivery_fee) : $t('free'),
            );
        }
        $rows[] = $sep;
        $rows[] = $this->leader($t('total'), $som((int) $order->total).' '.$t('som'));
        $out[] = '<pre>'.$esc(implode("\n", $rows)).'</pre>';
        $out[] = '';

        // --- Manzil / olib ketish ---
        if ($pickup) {
            $out[] = '🛍 '.$esc($t('pickup')).': '.$esc($order->restaurant->name);
            if (filled($order->restaurant->district?->name)) {
                $out[] = '   '.$esc($order->restaurant->district->name);
            }
            if (filled($order->restaurant->phone)) {
                $out[] = '   📞 '.$esc($order->restaurant->phone);
            }
            $hours = $order->restaurant->workHours()->formatFor(now(config('app.display_timezone')));
            if ($hours !== null) {
                $out[] = '   ⏱ '.$esc($t('work_hours')).': '.$esc($hours);
            }
        } else {
            $out[] = '📍 '.$esc($t('address')).': '.$esc(OrderAddress::line($order->address_snapshot) ?? '—');
        }

        // --- Izoh (mijoz checkout paytida yozgan bo'lsa) ---
        if (filled($order->note)) {
            $out[] = '📝 '.$esc($t('note')).': '.$esc($order->note);
        }

        // --- To'lov + ETA ---
        $out[] = '💳 '.$esc($t('payment')).': '.$esc((string) __("messages.payment_method.{$order->payment_method->value}", [], $locale));
        $out[] = '⏱ '.$esc($t('eta')).': '.$esc($t('eta_value', [
            'range' => EtaEstimate::fromMinutes((int) $order->eta_minutes)->range(),
        ]));

        $out[] = '';
        $out[] = $esc($t('footer'));

        return implode("\n", $out);
    }

    /**
     * "2x Lavash oddiy ......... 60 000" — chap matn, nuqta to'ldirgich, o'ng qiymat.
     * Umumiy kenglik WIDTH; chap matn sig'masa qisqartiriladi.
     */
    private function leader(string $label, string $value): string
    {
        $label = trim($label);
        $maxLabel = self::WIDTH - mb_strlen($value) - 2; // ikki bo'sh joy (matn|nuqta|qiymat)

        if ($maxLabel < 1) {
            return $label.' '.$value; // qiymat juda uzun — tekislashsiz
        }
        if (mb_strlen($label) > $maxLabel) {
            $label = rtrim(mb_substr($label, 0, $maxLabel - 1)).'…';
        }

        $dots = max(1, self::WIDTH - mb_strlen($label) - mb_strlen($value) - 2);

        return $label.' '.str_repeat('.', $dots).' '.$value;
    }
}
