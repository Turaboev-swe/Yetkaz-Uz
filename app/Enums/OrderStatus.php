<?php

namespace App\Enums;

enum OrderStatus: string
{
    case New = 'new';
    case Accepted = 'accepted';
    case Preparing = 'preparing';
    case OnTheWay = 'on_the_way';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /**
     * Restoran uchun "faol" buyurtma — navbat jarimasi hisobida va
     * oshxona panelida ko'rsatiladi.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::New, self::Accepted, self::Preparing, self::OnTheWay], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled], true);
    }

    /** @return list<self> */
    public static function active(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => $s->isActive()));
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return array_map(fn (self $s) => $s->value, self::active());
    }

    /** Oshxona xodimi qo'lda o'tkaza oladigan keyingi status. */
    public function next(): ?self
    {
        return match ($this) {
            self::New => self::Accepted,
            self::Accepted => self::Preparing,
            self::Preparing => self::OnTheWay,
            self::OnTheWay => self::Delivered,
            default => null,
        };
    }

    public function label(): string
    {
        return __("messages.order_status.{$this->value}");
    }
}
