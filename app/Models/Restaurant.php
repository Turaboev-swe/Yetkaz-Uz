<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Restaurant extends Model
{
    /** @use HasFactory<\Database\Factories\RestaurantFactory> */
    use HasFactory;

    public const POS_TYPES = ['jowi', 'poster', 'iiko', 'escpos', 'manual'];

    protected $fillable = [
        'name',
        'city_id',
        'lat',
        'lng',
        'phone',
        'logo_url',
        'avg_prep_time_min',
        'delivery_radius_km',
        'min_order_amount',
        'delivery_fee',
        'is_open',
        'work_hours',
        'pos_type',
        'printer_host',
        'printer_port',
        'pos_credentials',
    ];

    protected $hidden = [
        'pos_credentials',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'avg_prep_time_min' => 'integer',
            'delivery_radius_km' => 'float',
            'min_order_amount' => 'integer',
            'delivery_fee' => 'integer',
            'is_open' => 'boolean',
            'work_hours' => 'array',
            'pos_credentials' => 'encrypted:array',
            'printer_port' => 'integer',
        ];
    }

    /** @return BelongsTo<City, Restaurant> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return HasMany<Category> */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /** @return HasManyThrough<Product> */
    public function products(): HasManyThrough
    {
        return $this->hasManyThrough(Product::class, Category::class);
    }

    /** @return HasMany<Order> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Berilgan nuqtadan yetkazish radiusi ichidagi restoranlar.
     * Funksional GIST indeks (restaurants_geo_gist) bilan bir xil ifoda ishlatiladi.
     */
    public function scopeDeliversTo(Builder $query, float $lat, float $lng): Builder
    {
        return $query->whereRaw(
            'ST_DWithin(
                ST_SetSRID(ST_MakePoint(lng, lat), 4326)::geography,
                ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                delivery_radius_km * 1000
            )',
            [$lng, $lat]
        );
    }

    /** Masofani (km) tanlangan nuqtadan hisoblab, `distance_km` ustunini qo'shadi. */
    public function scopeWithDistanceKm(Builder $query, float $lat, float $lng): Builder
    {
        if (empty($query->getQuery()->columns)) {
            $query->select($query->qualifyColumn('*'));
        }

        return $query->selectRaw(
            'ST_Distance(
                ST_SetSRID(ST_MakePoint(restaurants.lng, restaurants.lat), 4326)::geography,
                ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
            ) / 1000 AS distance_km',
            [$lng, $lat]
        );
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('is_open', true);
    }
}
