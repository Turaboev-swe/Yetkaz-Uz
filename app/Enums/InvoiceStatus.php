<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';
    case Waived = 'waived';
    /** Xato tarif bilan yaratilgan va tuzatishda bekor qilingan hisob (qarz/statistikaga kirmaydi). */
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'To‘lanmagan',
            self::Partial => 'Qisman to‘langan',
            self::Paid => 'To‘langan',
            self::Waived => 'Kechirilgan',
            self::Void => 'Bekor qilingan',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unpaid => 'danger',
            self::Partial => 'warning',
            self::Paid => 'success',
            self::Waived, self::Void => 'gray',
        };
    }

    /** To'lov summasiga qarab holat (kechirilgan holatni chaqiruvchi alohida hal qiladi). */
    public static function fromAmounts(int $due, int $paid): self
    {
        if ($paid >= $due) {
            return self::Paid; // to'lanadigan summa 0 bo'lsa ham qarz yo'q
        }

        return $paid > 0 ? self::Partial : self::Unpaid;
    }
}
