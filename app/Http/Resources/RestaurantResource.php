<?php

namespace App\Http\Resources;

use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Restaurant */
class RestaurantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'logo_url' => $this->logo_url,
            'phone' => $this->phone,
            'city' => $this->whenLoaded('city', fn () => $this->city?->name),
            // Pul — tiyinda (1 so'm = 100 tiyin).
            'min_order_amount' => $this->min_order_amount,
            'delivery_fee' => $this->delivery_fee,
            'avg_prep_time_min' => $this->avg_prep_time_min,
            'distance_km' => $this->when(
                $this->distance_km !== null,
                fn () => round((float) $this->distance_km, 2),
            ),
        ];
    }
}
