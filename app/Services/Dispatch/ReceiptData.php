<?php

namespace App\Services\Dispatch;

use App\Enums\PaymentMethod;

/**
 * Buyurtma chekining strukturali ma'lumoti — BITTA manba: ESC/POS cheki (ReceiptFormatter::format) ham,
 * brauzer cheki (kitchen/receipt.blade.php) ham shundan quriladi. Qiymatlar xom (transliteratsiyasiz):
 * ESC/POS uchun ASCIIga o'girish formatterda, HTML esa kirill/apostrofni o'zi chiqaradi.
 *
 * Pul — tiyinda. Vaqt — `Asia/Tashkent` da tayyor satr (`when` — to'liq sana va vaqt; `whenShort` — bugungi buyurtma uchun faqat «17:58»,
 * boshqa kunniki «02.11 17:58»).
 */
final readonly class ReceiptData
{
    /**
     * @param  list<array{qty: int, name: string, note: ?string, total: ?int}>  $items  `total` — qator jami (buyurtma paytidagi
     *                                                                                  narx × soni, tiyin); narx snapshot'da bo'lmasa null
     * @param  ?string  $addressText  null — manzil yo'q (olib ketish yoki manzil snapshot'i bo'sh)
     */
    public function __construct(
        public string $restaurantName,
        public string $orderNumber,
        public bool $pickup,
        public ?string $when,
        public ?string $whenShort,
        public ?int $etaMinutes,
        public array $items,
        public int $itemsTotal,
        public ?string $note,
        public string $customerName,
        public string $phone,
        public ?string $telegramPhone,
        public ?string $addressText,
        public string $addressExtra,
        public ?string $landmark,
        public int $discount,
        public int $deliveryFee,
        public bool $deliveryPaidToTaxi,
        public int $total,
        public ?PaymentMethod $payment,
    ) {}

    /**
     * Qisqa hisob («Taomlar», «Chegirma», «Yetkazish») faqat taomlar summasidan farq qiladigan narsa bo'lganda
     * chiqadi — aks holda «MIJOZDAN OLINADI» o'sha summaning o'zi va takror qator keraksiz.
     */
    public function hasBreakdown(): bool
    {
        return $this->discount > 0 || $this->deliveryFee > 0;
    }

    /** Chek qismi: Payme/Click bo'lmasa — naqd. */
    public function paymentKey(): string
    {
        return match ($this->payment) {
            PaymentMethod::Payme => 'payme',
            PaymentMethod::Click => 'click',
            default => 'cash',
        };
    }
}
