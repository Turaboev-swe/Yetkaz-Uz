<?php

namespace App\Services\Dispatch;

use App\Enums\DeliveryType;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Buyurtmadan oshxona cheki — ESC/POS oqimi + o'qiladigan matn.
 *
 * Qog'oz kengligi (58/80 mm) va kodlash jadvali — restoran sozlamasi. Chekka faqat ASCII yoziladi:
 * kirill lotinga o'giriladi, emoji olib tashlanadi (EscPos::ascii).
 */
class ReceiptFormatter
{
    /** @return array{escpos: string, text: string} */
    public function format(Order $order, bool $diagnostics = false, bool $codepageTable = false): array
    {
        $order->loadMissing('restaurant', 'user');
        $restaurant = $order->restaurant;

        $som = fn (int $tiyin): string => number_format(intdiv($tiyin, 100), 0, '.', ' ').' som';
        $type = $order->delivery_type === DeliveryType::Pickup ? 'OLIB KETISH' : 'YETKAZISH';
        $when = $order->created_at?->timezone(config('app.display_timezone'))->format('d.m.Y H:i');

        $p = (new EscPos($restaurant->receiptColumns(), $restaurant->receiptCodepage()))->init();

        if ($diagnostics) {
            // Sinov cheki: qog'oz kengligi va kodlash jadvali to'g'ri sozlanganini ko'zda tekshirish uchun.
            $p->align('center')->bold(true)->text('*** TEST CHEK ***')->bold(false);
            $p->align('left')->text("Qogoz: {$restaurant->receiptPaperMm()} mm, {$p->width()} belgi");
            $p->text("Kodlash: ESC t {$restaurant->receiptCodepage()}");
            $p->text(substr(str_repeat('1234567890', 5), 0, $p->width())); // chap-o'ng chegara: chekka to'liq sig'ishi kerak
        }

        // Restoran nomi: sig'sa ikki baravar katta (kenglik x2), aks holda faqat balandlik x2, uzun bo'lsa qatorlarga.
        $title = EscPos::clean((string) $restaurant->name);
        $p->align('center')->bold(true);
        if (mb_strlen($title) <= intdiv($p->width(), 2)) {
            $p->size(1, 1)->text($title)->size(0, 0);
        } else {
            $p->size(0, 1);
            foreach ($p->wrap($title) as $line) {
                $p->text($line);
            }
            $p->size(0, 0);
        }
        $p->bold(false);
        $p->align('center')->bold(true)->text("*** {$type} ***")->bold(false);
        $p->align('left')->rule('=');

        $p->columns('Buyurtma:', $order->order_number);
        $p->columns('Vaqt:', (string) $when);
        if ($order->eta_minutes) {
            $p->columns('Tayyor:', "~{$order->eta_minutes} daq");
        }
        $p->rule();

        foreach ($order->items as $it) {
            $prefix = $it['qty'].'x  ';
            $lines = $p->wrap((string) $it['name'], $p->width() - mb_strlen($prefix), mb_strlen($prefix));
            $p->bold(true);
            foreach ($lines as $i => $line) {
                $p->text($i === 0 ? $prefix.$line : $line);
            }
            $p->bold(false);

            if (! empty($it['note'])) {
                foreach ($p->wrap((string) $it['note'], $p->width() - 7, 7) as $i => $line) {
                    $p->text($i === 0 ? '     ! '.$line : $line);
                }
            }
        }
        $p->rule();

        if ($order->note) {
            $p->bold(true)->text('IZOH:')->bold(false);
            foreach ($p->wrap($order->note) as $line) {
                $p->text($line);
            }
            $p->rule();
        }

        // Mijoz: ism va telefon — olib ketish buyurtmasida ham (mijoz kelganda topish/qo'ng'iroq uchun).
        $name = EscPos::clean((string) $order->user?->full_name);
        if ($name !== '') {
            foreach ($p->wrap('Mijoz: '.$name) as $line) {
                $p->text($line);
            }
        }
        $p->text('Tel: '.(EscPos::clean((string) $order->contactPhone()) ?: '-'));
        if ($order->hasDistinctContactPhone()) {
            $p->text('Tel (Telegram): '.EscPos::clean((string) $order->user->phone));
        }
        $p->rule();

        if ($order->delivery_type !== DeliveryType::Pickup && $order->address_snapshot) {
            $snap = $order->address_snapshot;
            $p->bold(true)->text('MANZIL:')->bold(false);
            foreach ($p->wrap(trim(($snap['address_text'] ?? '').' '.($snap['district'] ?? ''))) as $line) {
                $p->text($line);
            }
            $extra = array_filter([
                ($snap['entrance'] ?? null) ? 'kirish '.$snap['entrance'] : null,
                ($snap['floor'] ?? null) ? 'qavat '.$snap['floor'] : null,
                ($snap['apartment'] ?? null) ? 'xonadon '.$snap['apartment'] : null,
            ]);
            if ($extra) {
                foreach ($p->wrap(implode(', ', $extra)) as $line) {
                    $p->text($line);
                }
            }
            $p->rule();
        }

        // Egasi DM'i / oshxona xabari / kartasi bilan bir xil ma'no. Chekka faqat ASCII — emoji va "−" yo'q,
        // matn emojisiz, ASCII minus bilan. Restoran/platforma taqsimoti chekda KO'RSATILMAYDI — faqat hisobotlarda.
        if ($order->hasDiscount()) {
            $p->columns('Chegirma:', '-'.$som($order->discount_amount));
        }
        if ($order->delivery_paid_to_taxi) {
            // Faqat taomlar summasi olinadi; yetkazish haqini mijoz taksiga alohida to'laydi.
            foreach ($p->wrap('Yetkazish haqi: mijoz taksiga alohida tolaydi') as $line) {
                $p->text($line);
            }
        }
        $p->bold(true)->size(0, 1)->columns('MIJOZDAN OLINADI:', $som($order->total))->size(0, 0)->bold(false);
        $p->text('Tolov: '.$this->paymentLabel($order->payment_method));

        if ($codepageTable) {
            $this->codepageTable($p, $restaurant->receiptCodepage());
        }

        $p->feed(1)->align('center')->text('yetkaz.uz')->feed(3)->cut();

        return [
            'escpos' => $p->raw(),
            'text' => $p->plain(),
        ];
    }

    /**
     * Sinov cheki uchun namunaviy (saqlanmagan) buyurtma: uzun nom, kirill, emoji, ' bilan matnlar.
     * Real printerda kenglik, qatorlarga bo'linish va kodlashni tekshirish uchun (print-agent:test-receipt).
     */
    public function sampleOrder(Restaurant $restaurant): Order
    {
        $user = new User(['full_name' => 'Алишер Каримов 🙂 (Karimov)', 'phone' => '+998901112233']);

        $order = new Order;
        $order->forceFill([
            'order_number' => 'TEST-'.now()->format('His'),
            'delivery_type' => DeliveryType::Delivery,
            'eta_minutes' => 40,
            'items' => [
                ['name' => "Juda uzun nomli taom: Lag'mon ustiga qo'shimcha go'sht, sabzavot va achchiq sous (katta porsiya)", 'qty' => 2, 'note' => null],
                ['name' => 'Плов самаркандский с говядиной', 'qty' => 1, 'note' => "Кам ёғ, o'tkir emas 🌶"],
                ['name' => 'Шашлык 🍢 из баранины (Қўй гўшти)', 'qty' => 3, 'note' => null],
                ['name' => "O'zbek oshi — Toshkent uslubi", 'qty' => 1, 'note' => null],
            ],
            'note' => "Пожалуйста, без лука 🙏 va achchiq qilmang. Eshik oldiga qo'ng'iroq qiling — qo'ng'iroq ishlamasa, domofon 25",
            'address_snapshot' => [
                'address_text' => "Toshkent sh., Chilonzor tumani, Bunyodkor shoh ko'chasi, 12-uy",
                'district' => 'Chilonzor',
                'entrance' => '2', 'floor' => '5', 'apartment' => '45',
            ],
            'contact_phone' => '+998935556677',
            'discount_amount' => 5_000_00,
            'delivery_paid_to_taxi' => false,
            'total' => 245_000_00,
            'payment_method' => PaymentMethod::Cash,
        ]);
        $order->created_at = Carbon::now();
        $order->setRelation('restaurant', $restaurant);
        $order->setRelation('user', $user);

        return $order;
    }

    /** 0x80-0xFF belgilarini xom baytlar bilan chiqaradi: qaysi jadval kirill/lotin diakritikasiga mosligini ko'rish uchun. */
    private function codepageTable(EscPos $p, int $codepage): void
    {
        $p->rule();
        $p->text("Kodlash jadvali {$codepage} (0x80-0xFF):");

        for ($row = 8; $row <= 15; $row++) {
            $bytes = '';
            for ($i = 0; $i < 16; $i++) {
                $bytes .= chr($row * 16 + $i);
            }
            $p->rawBytes(strtoupper(dechex($row)).'x: '.$bytes, strtoupper(dechex($row)).'x: [16 bayt]');
        }
    }

    private function paymentLabel(?PaymentMethod $method): string
    {
        return match ($method) {
            PaymentMethod::Payme => 'Payme (onlayn)',
            PaymentMethod::Click => 'Click (onlayn)',
            default => 'naqd',
        };
    }
}
