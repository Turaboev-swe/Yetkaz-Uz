<?php

namespace App\Models;

use App\Models\Concerns\HasGeneratedLocation;
use Database\Factories\AddressFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Address extends Model
{
    /** @use HasFactory<AddressFactory> */
    use HasFactory;

    use HasGeneratedLocation;

    /** Ro'yxatdan o'tishda saqlanadigan asosiy manzil yorlig'i. */
    public const LABEL_HOME = 'Uy';

    public const LABEL_WORK = 'Ish';

    protected $fillable = [
        'user_id',
        'district_id',
        'label',
        'lat',
        'lng',
        'address_text',
        'resolved_address',
        'entrance',
        'floor',
        'apartment',
        'note',
        'is_default',
    ];

    /** `location` — PostGIS tomonidan lat/lng dan hisoblanadi, qo'lda yozilmaydi. */
    protected $hidden = [
        'location',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'is_default' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, Address> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<District, Address> */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /** @return HasMany<Order> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /**
     * Mijozga ko'rsatiladigan manzil. Xom koordinata HECH QACHON ko'rsatilmaydi —
     * u faqat backend va "Xaritada ko'rish" havolasi uchun.
     *
     * Tartib:  1. resolved_address (Nominatim ko'cha/tuman bergan bo'lsa)
     *          2. label ("Uy", "Ish")
     */
    public function displayAddress(): string
    {
        return filled($this->resolved_address)
            ? $this->resolved_address
            : ($this->label ?: '—');
    }
}
