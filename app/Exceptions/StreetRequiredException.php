<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * Geokoder ko'cha nomini topmadi, mijoz ham ko'cha yoki mo'ljal kiritmadi. 422 (`errors.street`):
 * Mini App/bot buni tanib, ko'cha yoki mo'ljalni so'raydi.
 */
class StreetRequiredException extends ValidationException
{
    public static function make(): static
    {
        return static::withMessages(['street' => __('messages.street_required')]);
    }
}
