<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Enums\PromoCodeError;
use Database\Factories\PromoCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'min_order_amount',
        'restaurant_share_percent',
        'per_user_limit',
        'total_usage_limit',
        'is_active',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'discount_type' => DiscountType::class,
            'discount_value' => 'integer',
            'min_order_amount' => 'integer',
            'restaurant_share_percent' => 'integer',
            'per_user_limit' => 'integer',
            'total_usage_limit' => 'integer',
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

    /**
     * Kod ishlaydigan restoranlar — faqat admin tanlaganlari. "Barcha restoranlar"
     * varianti yo'q: restoransiz kod hech qayerda ishlamaydi.
     *
     * @return BelongsToMany<Restaurant>
     */
    public function restaurants(): BelongsToMany
    {
        return $this->belongsToMany(Restaurant::class, 'promo_code_restaurant');
    }

    public function appliesTo(Restaurant $restaurant): bool
    {
        return $this->restaurants()->whereKey($restaurant->getKey())->exists();
    }

    /** @return HasMany<Order> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Ishlatilish hisoblanadigan buyurtmalar — BEKOR QILINMAGANLARI. Bekor
     * qilingan buyurtma limitni band qilmaydi (mijoz kodni qayta ishlata oladi).
     *
     * @return HasMany<Order>
     */
    public function usages(): HasMany
    {
        return $this->orders()->where('status', '!=', OrderStatus::Cancelled->value);
    }

    /**
     * Limitsiz tekshiruvlar (faollik, muddat, restoran) — birinchi mos kelmagan
     * sabab, hammasi joyida bo'lsa null. Limitlar bazadagi buyurtmalarни
     * sanaydi — ular PromoCodeService'da (qatorни qulflagan holda).
     */
    public function rejectionFor(Restaurant $restaurant, ?Carbon $now = null): ?PromoCodeError
    {
        $now ??= now();

        return match (true) {
            ! $this->is_active => PromoCodeError::Inactive,
            $this->ends_at !== null && $now->gt($this->ends_at) => PromoCodeError::Expired,
            $this->starts_at !== null && $now->lt($this->starts_at) => PromoCodeError::NotStarted,
            ! $this->appliesTo($restaurant) => PromoCodeError::WrongRestaurant,
            default => null,
        };
    }

    /**
     * Minimal summa bajarilganmi — faqat TAOMLAR summasi (subtotal), yetkazish
     * narxi qo'shilmaydi. Aniq min_order_amount ga teng bo'lsa — ishlaydi.
     */
    public function meetsMinimum(int $subtotal): bool
    {
        return $this->min_order_amount === null || $subtotal >= $this->min_order_amount;
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
