<?php

namespace App\Services\Dispatch;

use App\Enums\PaymentMethod;

/**
 * Buyurtma chekining strukturali ma'lumoti — BITTA manba: ESC/POS cheki (ReceiptFormatter::format) ham,
 * brauzer cheki (kitchen/receipt.blade.php) ham shundan quriladi. Qiymatlar xom (transliteratsiyasiz):
 * ESC/POS uchun ASCIIga o'girish formatterda, HTML esa kirill/apostrofni o'zi chiqaradi.
 *
 * Pul — tiyinda. Vaqt — `Asia/Tashkent` da tayyor satr.
 */
final readonly class ReceiptData
{
    /**
     * @param  list<array{qty: int, name: string, note: ?string}>  $items
     * @param  ?string  $addressText  null — manzil yo'q (olib ketish yoki manzil snapshot'i bo'sh)
     */
    public function __construct(
        public string $restaurantName,
        public string $orderNumber,
        public bool $pickup,
        public ?string $when,
        public ?int $etaMinutes,
        public array $items,
        public ?string $note,
        public string $customerName,
        public string $phone,
        public ?string $telegramPhone,
        public ?string $addressText,
        public string $addressExtra,
        public int $discount,
        public bool $deliveryPaidToTaxi,
        public int $total,
        public ?PaymentMethod $payment,
    ) {}

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
