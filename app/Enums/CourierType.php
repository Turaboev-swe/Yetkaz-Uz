<?php

namespace App\Enums;

enum CourierType: string
{
    case OwnStaff = 'own_staff';
    case Taxi = 'taxi';

    public function label(): string
    {
        return __("messages.courier_type.{$this->value}");
    }

    public function icon(): string
    {
        return match ($this) {
            self::OwnStaff => '🛵',
            self::Taxi => '🚕',
        };
    }
}
