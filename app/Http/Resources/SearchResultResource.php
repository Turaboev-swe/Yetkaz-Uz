<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Umumiy qidiruv natijasi: taom + qaysi restoranda + narxi + masofa.
 *
 * @mixin Product
 */
class SearchResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $restaurant = $this->category->restaurant;

        return [
            'product' => [
                'id' => $this->id,
                'name' => $this->name,
                'description' => $this->description,
                'price' => $this->price, // tiyin
                'photo_url' => $this->photo_url,
                'prep_time_min' => $this->prep_time_min,
            ],
            'restaurant' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'logo_url' => $restaurant->logo_url,
                'min_order_amount' => $restaurant->min_order_amount,
                'delivery_fee' => $restaurant->delivery_fee,
            ],
            'distance_km' => round((float) $this->distance_km, 2),
        ];
    }
}
