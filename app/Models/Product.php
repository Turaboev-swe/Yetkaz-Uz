<?php

namespace App\Models;

use App\Models\Concerns\ScopedToRestaurant;
use App\Observers\ProductObserver;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy(ProductObserver::class)]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use ScopedToRestaurant;

    protected $fillable = [
        'category_id',
        'name',
        'description',
        'price',
        'old_price',
        'photo_url',
        'prep_time_min',
        'is_available',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'old_price' => 'integer',
            'prep_time_min' => 'integer',
            'is_available' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** Taom aksiyada — `old_price` bor va joriy narxdan yuqori. */
    public function isOnSale(): bool
    {
        return $this->old_price !== null && $this->old_price > $this->price;
    }

    /** @return BelongsTo<Category, Product> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** Taom qaysi restoranga tegishli (kategoriya orqali). */
    public function restaurant(): ?Restaurant
    {
        return $this->category?->restaurant;
    }

    /** @return HasMany<ProductPriceHistory> */
    public function priceHistory(): HasMany
    {
        return $this->hasMany(ProductPriceHistory::class)->orderByDesc('changed_at');
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_available', true);
    }

    /** RestaurantScope tomonidan chaqiriladi (restaurant_owner uchun). */
    public function scopeForRestaurant(Builder $query, int $restaurantId): Builder
    {
        return $query->whereIn(
            $this->qualifyColumn('category_id'),
            Category::query()
                ->withoutGlobalScopes()
                ->where('restaurant_id', $restaurantId)
                ->select('id'),
        );
    }

    /**
     * Umumiy taom qidiruvi ("lag'mon" -> barcha restoranlardagi mos taomlar).
     *
     * word_similarity — qidiruv so'zi nomdagi bironta so'zga o'xshasa yetadi
     * ("lag'mon" -> "Tovuqli lag'mon"); ILIKE — aniq qism-satr.
     * Tartib: eng o'xshashi birinchi.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        return $query
            ->whereRaw('(word_similarity(f_unaccent(?), f_unaccent(name)) >= 0.4 OR f_unaccent(name) ILIKE ?)', [
                $term,
                '%'.$term.'%',
            ])
            ->orderByRaw('word_similarity(f_unaccent(?), f_unaccent(name)) DESC', [$term]);
    }
}
