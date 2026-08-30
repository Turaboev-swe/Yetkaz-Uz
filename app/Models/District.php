<?php

namespace App\Models;

use Database\Factories\DistrictFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tuman / shahar. `center_lat/lng` faqat xaritani markazlashtirish va
 * ko'rsatish uchun — masofa/radius/ETA restoran va manzil lat/lng dan.
 */
class District extends Model
{
    /** @use HasFactory<DistrictFactory> */
    use HasFactory;

    protected $fillable = [
        'region_id',
        'name',
        'center_lat',
        'center_lng',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'center_lat' => 'float',
            'center_lng' => 'float',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Region, District> */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /** @return HasMany<Restaurant> */
    public function restaurants(): HasMany
    {
        return $this->hasMany(Restaurant::class);
    }

    /** @return HasMany<Address> */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
