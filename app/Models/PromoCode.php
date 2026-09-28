<?php

namespace App\Models;

use App\Enums\DiscountType;
use Database\Factories\PromoCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class PromoCode extends Model
{
    /** @use HasFactory<PromoCodeFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'discount_type',
        'discount_value',
        'restaurant_share_percent',
        'restaurant_id',
        'is_active',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'discount_type' => DiscountType::class,
            'discount_value' => 'integer',
            'restaurant_share_percent' => 'integer',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** Kodni doim katta harfda saqlaymiz — mijoz/admin qanday yozishidan qat'i nazar bir xil taqqoslanadi. */
    protected function setCodeAttribute(string $value): void
    {
        $this->attributes['code'] = mb_strtoupper(trim($value));
    }

    /** @return BelongsTo<Restaurant, PromoCode> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /** @return HasMany<Order> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** Shu restoranda (yoki restaurant_id=null bo'lsa — hamma joyda) hozir ishlatsa bo'ladimi. */
    public function isUsableFor(Restaurant $restaurant, ?Carbon $now = null): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->restaurant_id !== null && $this->restaurant_id !== $restaurant->id) {
            return false;
        }

        $now ??= now();

        if ($this->starts_at !== null && $now->lt($this->starts_at)) {
            return false;
        }

        if ($this->ends_at !== null && $now->gt($this->ends_at)) {
            return false;
        }

        return true;
    }

    /**
     * Buyurtma summasidan chegirma miqdorini hisoblaydi (tiyinда, doim BUTUN SO'M).
     *
     * Natija butun so'mgacha PASTGA yaxlitlanadi (100 tiyinга karrali): kasrli
     * so'm (masalan 12 345 so'mning 15% = 1 851,75) total'ni ham kasrli qilib,
     * Mini App (Math.round) va panel/Telegram (intdiv) bir buyurtmaga turli
     * summa ko'rsatardi. Pastga — mijozga e'lon qilingandan ko'p chegirma
     * berilmaydi. Natija `$subtotal` dan oshmaydi.
     */
    public function discountFor(int $subtotal): int
    {
        $raw = $this->discount_type === DiscountType::Percent
            ? intdiv($subtotal * $this->discount_value, 100)
            : $this->discount_value;

        return intdiv(max(0, min($raw, $subtotal)), 100) * 100;
    }
}
