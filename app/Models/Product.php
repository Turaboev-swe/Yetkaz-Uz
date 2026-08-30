<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory;

    protected $fillable = [
        'category_id',
        'name',
        'description',
        'price',
        'photo_url',
        'prep_time_min',
        'is_available',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'prep_time_min' => 'integer',
            'is_available' => 'boolean',
            'sort_order' => 'integer',
        ];
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

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_available', true);
    }

    /**
     * Umumiy taom qidiruvi ("lag'mon" -> barcha restoranlardagi mos taomlar).
     *
     * - `%`   : pg_trgm o'xshashlik operatori (products_name_unaccent_trgm GIN indeksi)
     * - ILIKE : qism-satr mosligi (qisqa so'rovlar uchun)
     * Tartib: eng o'xshashi birinchi.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        return $query
            ->whereRaw('(f_unaccent(name) % f_unaccent(?) OR f_unaccent(name) ILIKE ?)', [
                $term,
                '%'.$term.'%',
            ])
            ->orderByRaw('similarity(f_unaccent(name), f_unaccent(?)) DESC', [$term]);
    }
}
