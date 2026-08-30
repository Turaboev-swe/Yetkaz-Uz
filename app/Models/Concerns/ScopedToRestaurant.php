<?php

namespace App\Models\Concerns;

use App\Models\Scopes\RestaurantScope;

/**
 * Modelga RestaurantScope global scope'ini qo'shadi. Model
 * `scopeForRestaurant(Builder $query, int $restaurantId)` ni yozishi shart.
 */
trait ScopedToRestaurant
{
    public static function bootScopedToRestaurant(): void
    {
        static::addGlobalScope(new RestaurantScope);
    }
}
