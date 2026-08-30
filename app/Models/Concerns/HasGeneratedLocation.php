<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * `location` — PostGIS `GENERATED ALWAYS` ustuni (lat/lng dan hisoblanadi).
 * Unga hech qachon yozib bo'lmaydi, shu sabab har qanday INSERT/UPDATE oldidan
 * atributlardan olib tashlanadi (replicate(), qo'lda tayinlash va h.k. uchun ham).
 */
trait HasGeneratedLocation
{
    public static function bootHasGeneratedLocation(): void
    {
        static::saving(function (Model $model): void {
            unset($model->attributes['location']);
        });
    }
}
