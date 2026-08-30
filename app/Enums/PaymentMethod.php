<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Payme = 'payme';
    case Click = 'click';

    /** To'lov provayderi orqali onlayn to'lanadimi. */
    public function isOnline(): bool
    {
        return $this !== self::Cash;
    }

    public function label(): string
    {
        return __("messages.payment_method.{$this->value}");
    }
}
