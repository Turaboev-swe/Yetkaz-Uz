<?php

namespace App\Services\Dispatch;

use App\Enums\DeliveryType;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\OrderAddress;
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
        $d = $this->data($order);
        $restaurant = $order->restaurant;

        $som = self::som(...);
        $type = $d->pickup ? 'OLIB KETISH' : 'YETKAZISH';

        $p = (new EscPos($restaurant->receiptColumns(), $restaurant->receiptCodepage()))->init();

        if ($diagnostics) {
            // Sinov cheki: qog'oz kengligi va kodlash jadvali to'g'ri sozlanganini ko'zda tekshirish uchun.
            $p->align('center')->bold(true)->text('*** TEST CHEK ***')->bold(false);
            $p->align('left')->text("Qogoz: {$restaurant->receiptPaperMm()} mm, {$p->width()} belgi");
            $p->text("Kodlash: ESC t {$restaurant->receiptCodepage()}");
            $p->text(substr(str_repeat('1234567890', 5), 0, $p->width())); // chap-o'ng chegara: chekka to'liq sig'ishi kerak
        }

        // Restoran nomi: sig'sa ikki baravar katta (kenglik x2), aks holda faqat balandlik x2, uzun bo'lsa qatorlarga.
        $title = EscPos::clean($d->restaurantName);
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

        $p->columns('Buyurtma:', $d->orderNumber);
        $p->columns('Vaqt:', (string) $d->when);
        if ($d->etaMinutes) {
            $p->columns('Tayyor:', "~{$d->etaMinutes} daq");
        }
        $p->rule();

        foreach ($d->items as $it) {
            $prefix = $it['qty'].'x  ';
            // Qator jami o'ng ustunda (birinchi qator oxirida); nom unga yetmaydigan qilib o'raladi.
            $price = $it['total'] === null ? '' : self::amount($it['total']);
            $first = $p->width() - mb_strlen($prefix) - ($price === '' ? 0 : mb_strlen($price) + 1);
            $lines = $p->wrap((string) $it['name'], max(1, $first), mb_strlen($prefix)) ?: [''];
            $p->bold(true);
            foreach ($lines as $i => $line) {
                if ($i === 0) {
                    $price === '' ? $p->text($prefix.$line) : $p->columns($prefix.$line, $price);
                } else {
                    $p->text($line);
                }
            }
            $p->bold(false);

            if ($it['note'] !== null) {
                foreach ($p->wrap((string) $it['note'], $p->width() - 7, 7) as $i => $line) {
                    $p->text($i === 0 ? '     ! '.$line : $line);
                }
            }
        }
        $p->rule();

        if ($d->note !== null) {
            $p->bold(true)->text('IZOH:')->bold(false);
            foreach ($p->wrap($d->note) as $line) {
                $p->text($line);
            }
            $p->rule();
        }

        // Mijoz: ism va telefon — olib ketish buyurtmasida ham (mijoz kelganda topish/qo'ng'iroq uchun).
        $name = EscPos::clean($d->customerName);
        if ($name !== '') {
            foreach ($p->wrap('Mijoz: '.$name) as $line) {
                $p->text($line);
            }
        }
        $p->text('Tel: '.(EscPos::clean($d->phone) ?: '-'));
        if ($d->telegramPhone !== null) {
            $p->text('Tel (Telegram): '.EscPos::clean($d->telegramPhone));
        }
        $p->rule();

        if ($d->addressText !== null) {
            $p->bold(true)->text('MANZIL:')->bold(false);
            foreach ($p->wrap($d->addressText) as $line) {
                $p->text($line);
            }
            if ($d->addressExtra !== '') {
                foreach ($p->wrap($d->addressExtra) as $line) {
                    $p->text($line);
                }
            }
            if ($d->landmark !== null) {
                $p->bold(true)->text('MOLJAL:')->bold(false);
                foreach ($p->wrap($d->landmark) as $line) {
                    $p->text($line);
                }
            }
            $p->rule();
        }

        // Egasi DM'i / oshxona xabari / kartasi bilan bir xil ma'no. Chekka faqat ASCII — emoji va "−" yo'q,
        // matn emojisiz, ASCII minus bilan. Restoran/platforma taqsimoti chekda KO'RSATILMAYDI — faqat hisobotlarda.
        // Qisqa hisob: qator jami - chegirma + yetkazish = MIJOZDAN OLINADI (faqat qiymati borlari).
        if ($d->hasBreakdown()) {
            $p->columns('Taomlar:', $som($d->itemsTotal));
        }
        if ($d->discount > 0) {
            $p->columns('Chegirma:', '-'.$som($d->discount));
        }
        if ($d->deliveryFee > 0) {
            $p->columns('Yetkazish:', $som($d->deliveryFee));
        }
        if ($d->deliveryPaidToTaxi) {
            // Faqat taomlar summasi olinadi; yetkazish haqini mijoz taksiga alohida to'laydi.
            foreach ($p->wrap('Yetkazish haqi: mijoz taksiga alohida tolaydi') as $line) {
                $p->text($line);
            }
        }
        $p->bold(true)->size(0, 1)->columns('MIJOZDAN OLINADI:', $som($d->total))->size(0, 0)->bold(false);
        $p->text('Tolov: '.$this->paymentLabel($d->payment));

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
     * Chek ma'lumoti — ESC/POS cheki ham, brauzer cheki ham shundan quriladi (mantiq bitta joyda:
     * qaysi qator qachon chiqishi, telefonlar farqi, manzil, chegirma). Qiymatlar xom, formatlashsiz.
     */
    public function data(Order $order): ReceiptData
    {
        $order->loadMissing('restaurant', 'user');

        $pickup = $order->delivery_type === DeliveryType::Pickup;
        $tz = config('app.display_timezone');
        $createdAt = $order->created_at?->timezone($tz);

        // Narx — buyurtma paytidagi SNAPSHOT (items.price, tiyin, bir dona uchun), menyudagi hozirgi narx emas.
        $items = [];
        foreach ($order->items as $it) {
            $qty = (int) $it['qty'];
            $items[] = [
                'qty' => $qty,
                'name' => (string) $it['name'],
                'note' => ! empty($it['note']) ? (string) $it['note'] : null,
                'total' => isset($it['price']) ? (int) $it['price'] * $qty : null,
            ];
        }
        $priced = $items !== [] && ! in_array(null, array_column($items, 'total'), true);
        $itemsTotal = $priced ? (int) array_sum(array_column($items, 'total')) : (int) $order->subtotal;

        $addressText = null;
        $addressExtra = '';
        if (! $pickup && $order->address_snapshot) {
            $snap = $order->address_snapshot;
            // Tuman matnda bo'lsa qayta qo'shilmaydi (apostrof/registr farqiga qaramay); xom koordinata — tuman nomi.
            $addressText = OrderAddress::line($snap) ?? '';
            $addressExtra = implode(', ', array_filter([
                ($snap['entrance'] ?? null) ? 'kirish '.$snap['entrance'] : null,
                ($snap['floor'] ?? null) ? 'qavat '.$snap['floor'] : null,
                ($snap['apartment'] ?? null) ? 'xonadon '.$snap['apartment'] : null,
            ]));
        }

        return new ReceiptData(
            restaurantName: (string) $order->restaurant?->name,
            orderNumber: (string) $order->order_number,
            pickup: $pickup,
            when: $createdAt?->format('d.m.Y H:i'),
            whenShort: $createdAt === null ? null : ($createdAt->isSameDay(now($tz)) ? $createdAt->format('H:i') : $createdAt->format('d.m H:i')),
            etaMinutes: $order->eta_minutes ? (int) $order->eta_minutes : null,
            items: $items,
            itemsTotal: $itemsTotal,
            note: $order->note ? (string) $order->note : null,
            // Mijoz: ism va telefon — olib ketish buyurtmasida ham (mijoz kelganda topish/qo'ng'iroq uchun).
            customerName: self::realName((string) $order->user?->full_name),
            phone: (string) $order->contactPhone(),
            telegramPhone: $order->hasDistinctContactPhone() ? (string) $order->user->phone : null,
            addressText: $addressText,
            addressExtra: $addressExtra,
            // Mo'ljal faqat yetkazishda (olib ketishda manzil yo'q).
            landmark: $pickup ? null : OrderAddress::landmark($order->address_snapshot),
            discount: $order->hasDiscount() ? (int) $order->discount_amount : 0,
            // Taksi rejimida yetkazish haqi summaga qo'shilmaydi (mijoz taksiga alohida to'laydi; fee = 0).
            deliveryFee: $order->delivery_paid_to_taxi ? 0 : (int) $order->delivery_fee,
            deliveryPaidToTaxi: (bool) $order->delivery_paid_to_taxi,
            total: (int) $order->total,
            payment: $order->payment_method,
        );
    }

    /** Mijoz ismi o'rnida turadigan umumiy so'zlar — ism emas, chekka chiqarilmaydi. */
    private const PLACEHOLDER_NAMES = ['mijoz', 'клиент', 'customer'];

    /** Haqiqiy ism yoki '' (bo'sh ism va «Mijoz» kabi o'rinbosar so'z — ism yo'q). */
    private static function realName(string $name): string
    {
        $name = trim($name);

        return in_array(mb_strtolower($name), self::PLACEHOLDER_NAMES, true) ? '' : $name;
    }

    /** Tiyinni «245 000» ko'rinishiga (so'mda, mingliklar bo'shliq bilan). */
    public static function amount(int $tiyin): string
    {
        return number_format(intdiv($tiyin, 100), 0, '.', ' ');
    }

    /** Tiyinni «245 000 som» ko'rinishiga (ASCII — ESC/POS cheki). */
    public static function som(int $tiyin): string
    {
        return self::amount($tiyin).' som';
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
