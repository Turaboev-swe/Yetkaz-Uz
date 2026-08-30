<?php

namespace App\Models\Scopes;

use App\Models\Staff;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * `restaurant_owner` autentifikatsiya qilinganda so'rovni faqat uning
 * restoraniga cheklaydi. Boshqa har qanday kontekstda (platform_admin,
 * Telegram bot, Mini App API, CLI, testlar) — no-op.
 *
 * Model `scopeForRestaurant(Builder, int $restaurantId)` ni amalga oshirishi shart.
 */
class RestaurantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $staff = auth('staff')->user();

        if (! $staff instanceof Staff
            || ! $staff->isRestaurantOwner()
            || $staff->restaurant_id === null) {
            return;
        }

        $model->scopeForRestaurant($builder, $staff->restaurant_id);
    }
}
