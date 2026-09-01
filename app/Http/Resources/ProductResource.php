<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price, // tiyin
            // Aksiyada bo'lsa — eski (ustidan chiziladigan) narx, aks holda null.
            'old_price' => $this->when($this->resource->isOnSale(), fn () => $this->old_price),
            'photo_url' => Media::url($this->photo_url),
            'prep_time_min' => $this->prep_time_min,
        ];
    }
}
