<?php

namespace App\Observers;

use App\Models\Product;
use App\Models\ProductPriceHistory;

class ProductObserver
{
    /**
     * Narx o'zgarганда product_price_history ga yozadi (narxlar tiyinda).
     * Kim o'zgartirgani `staff` guard'idan olinadi (panel konteksti).
     */
    public function updated(Product $product): void
    {
        if (! $product->wasChanged('price')) {
            return;
        }

        ProductPriceHistory::create([
            'product_id' => $product->id,
            'staff_id' => auth('staff')->id(),
            'old_price' => (int) $product->getOriginal('price'),
            'new_price' => (int) $product->price,
            'changed_at' => now(),
        ]);
    }
}
