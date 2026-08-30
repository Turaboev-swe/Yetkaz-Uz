<?php

namespace App\Models;

use Database\Factories\ProductPriceHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPriceHistory extends Model
{
    /** @use HasFactory<ProductPriceHistoryFactory> */
    use HasFactory;

    protected $table = 'product_price_history';

    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'staff_id',
        'old_price',
        'new_price',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'old_price' => 'integer',
            'new_price' => 'integer',
            'changed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Product, ProductPriceHistory> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Staff, ProductPriceHistory> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
