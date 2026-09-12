<?php

namespace App\Enums;

enum BroadcastAudience: string
{
    case All = 'all';
    case District = 'district';

    public function label(): string
    {
        return __("messages.broadcast_audience.{$this->value}");
    }
}
