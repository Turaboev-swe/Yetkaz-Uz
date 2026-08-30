<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class City extends Model
{
    /** @use HasFactory<\Database\Factories\CityFactory> */
    use HasFactory;

    protected $fillable = [
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

    /** @return HasMany<Restaurant> */
    public function restaurants(): HasMany
    {
        return $this->hasMany(Restaurant::class);
    }
}
