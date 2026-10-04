<?php

namespace App\Enums;

/** Banner bosilganda nima bo'ladi. */
enum BannerTarget: string
{
    case None = 'none';
    case Restaurant = 'restaurant';

    public function label(): string
    {
        return __("messages.banner_target.{$this->value}");
    }
}
