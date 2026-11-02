<?php

namespace App\Enums;

enum BillingPaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Transfer = 'transfer';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Naqd',
            self::Card => 'Karta',
            self::Transfer => 'O‘tkazma',
        };
    }
}
