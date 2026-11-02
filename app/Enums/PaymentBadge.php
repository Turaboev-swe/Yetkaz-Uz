<?php

namespace App\Enums;

/** "Joriy oy" sahifasi: restoranning HOZIR to'lanishi kerak hisobi holati (badge). */
enum PaymentBadge: string
{
    case NotApplicable = 'none';
    case AtMonthEnd = 'at_month_end';
    case NoInvoice = 'no_invoice';
    case Unpaid = 'unpaid';
    case Overdue = 'overdue';
    case Partial = 'partial';
    case Paid = 'paid';
    case Waived = 'waived';

    public function label(): string
    {
        return match ($this) {
            self::NotApplicable => '—',
            self::AtMonthEnd => 'Oy oxirida',
            self::NoInvoice => 'Hisob yaratilmagan',
            self::Unpaid => 'To‘lanmagan',
            self::Overdue => 'Muddati o‘tgan',
            self::Partial => 'Qisman',
            self::Paid => 'To‘langan',
            self::Waived => 'Kechirilgan',
        };
    }

    /** Filament rangi; `overdue` — to'q qizil (alohida ro'yxatdan o'tgan palitra emas, sahifada Color::hex). */
    public function color(): string
    {
        return match ($this) {
            self::Paid => 'success',
            self::Partial => 'warning',
            self::Unpaid, self::Overdue => 'danger',
            default => 'gray',
        };
    }

    /** "To'landi" amali ochiladigan holatlar (qarz bor). */
    public function hasDebt(): bool
    {
        return in_array($this, [self::Unpaid, self::Overdue, self::Partial], true);
    }

    /** Bosilganda hisobning ko'rish sahifasiga o'tadigan holatlar. */
    public function opensInvoice(): bool
    {
        return in_array($this, [self::Paid, self::Waived], true);
    }
}
