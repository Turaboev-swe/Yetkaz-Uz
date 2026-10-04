<?php

namespace App\Models;

use App\Enums\BannerTarget;
use Carbon\CarbonInterface;
use Database\Factories\BannerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Mini App bosh sahifasidagi reklama banneri. `title` — faqat admin uchun ichki
 * nom. starts_at/ends_at bazada UTC; panelда Toshkent vaqtida kiritiladi.
 */
class Banner extends Model
{
    /** @use HasFactory<BannerFactory> */
    use HasFactory;

    protected $fillable = [
        'image_path',
        'title',
        'target_type',
        'restaurant_id',
        'sort_order',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'target_type' => BannerTarget::class,
            'sort_order' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // "Hech narsa" tanlansa eski restoran bog'lanishi qolib ketmasin (panelда
        // yashirin maydon saqlanmaydi; bazadagi CHECK buni baribir talab qiladi).
        static::saving(function (Banner $banner): void {
            if ($banner->target_type === BannerTarget::None) {
                $banner->restaurant_id = null;
            }
        });

        // Almashtirilgan / o'chirilgan banner rasmi diskda yetim qolmasin.
        static::updated(function (Banner $banner): void {
            if ($banner->wasChanged('image_path')) {
                self::deleteImage($banner->getOriginal('image_path'));
            }
        });
        static::deleted(fn (Banner $banner) => self::deleteImage($banner->image_path));
    }

    private static function deleteImage(?string $path): void
    {
        if (filled($path) && ! str_starts_with($path, 'http')) {
            Storage::disk('public')->delete($path);
        }
    }

    /** @return BelongsTo<Restaurant, Banner> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * Mijozga ko'rsatiladiganlar: faol, muddati ichida (ends_at — shu lahzani
     * ham o'z ichiga oladi, promo-kod bilan bir xil), restoran banneri bo'lsa —
     * restoran `is_open`. Ish vaqti (work_hours) bu yerda EMAS — u JSON jadval,
     * BannerFeed uni PHP'da `Restaurant::isOpenAt()` bilan tekshiradi.
     */
    public function scopeVisibleAt(Builder $query, CarbonInterface $now): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('starts_at', '<=', $now)
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->where(fn (Builder $q) => $q
                ->where('target_type', BannerTarget::None->value)
                ->orWhere(fn (Builder $q) => $q
                    ->where('target_type', BannerTarget::Restaurant->value)
                    ->whereHas('restaurant', fn (Builder $r) => $r->where('is_open', true))));
    }

    /**
     * Panel ro'yxatidagi holat — BannerFeed bilan bir xil shartlar, birinchi
     * mos kelmagani: off | scheduled | expired | restaurant_closed | live.
     * restaurant_closed — `is_open` o'chiq YOKI hozir ish vaqtidan tashqari.
     */
    public function statusAt(CarbonInterface $now): string
    {
        return match (true) {
            ! $this->is_active => 'off',
            $this->starts_at->gt($now) => 'scheduled',
            $this->ends_at !== null && $this->ends_at->lt($now) => 'expired',
            $this->target_type === BannerTarget::Restaurant && ! $this->restaurant?->isOpenAt($now) => 'restaurant_closed',
            default => 'live',
        };
    }
}
