<?php

namespace App\Services\Ordering;

use App\Models\Order;
use RuntimeException;

/**
 * Buyurtma raqami: "YT-" + 6 ta raqam (000000–999999, boshida nol bo'lishi mumkin).
 * Masalan: YT-483920.
 *
 * Takrorlanmaslik — bazaда tekshiriladi; to'qnashuvда qayta uriniladi
 * (999 999 kombinatsiya bor, real hajmда to'qnashuv deyarli imkonsiz).
 *
 * Eski YK-XXXXXX raqamli buyurtmalar o'zgarmaydi — faqat yangi generatsiya.
 */
class OrderNumberGenerator
{
    private const PREFIX = 'YT-';

    private const MAX_ATTEMPTS = 10;

    public function generate(): string
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $number = self::PREFIX.$this->sixDigits();

            if (! Order::query()->where('order_number', $number)->exists()) {
                return $number;
            }
        }

        throw new RuntimeException(
            'Buyurtma raqamini yaratib bo\'lmadi: '.self::MAX_ATTEMPTS.' urinish ham band chiqdi.'
        );
    }

    /** Testда to'qnashuvni majburlash uchun override qilinadi. */
    protected function sixDigits(): string
    {
        return str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
    }
}
