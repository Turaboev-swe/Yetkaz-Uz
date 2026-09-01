<?php

namespace App\Enums;

enum DeliveryType: string
{
    case Delivery = 'delivery';
    case Pickup = 'pickup';

    public function isPickup(): bool
    {
        return $this === self::Pickup;
    }

    public function label(): string
    {
        return __("messages.delivery_type.{$this->value}");
    }
}
