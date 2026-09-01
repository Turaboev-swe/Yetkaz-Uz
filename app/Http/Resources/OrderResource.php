<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'delivery_type' => $this->delivery_type->value,
            'payment_method' => $this->payment_method->value,
            'payment_status' => $this->payment_status->value,

            'items' => $this->items, // [{product_id, name, price(tiyin), qty, note}]
            'note' => $this->note,

            // Pul — tiyinda.
            'subtotal' => $this->subtotal,
            'delivery_fee' => $this->delivery_fee,
            'total' => $this->total,

            'eta_minutes' => $this->eta_minutes,
            'distance_km' => $this->distance_km !== null ? round((float) $this->distance_km, 2) : null,

            'address_snapshot' => $this->address_snapshot,
            'restaurant' => $this->whenLoaded('restaurant', fn () => [
                'id' => $this->restaurant->id,
                'name' => $this->restaurant->name,
                'phone' => $this->restaurant->phone,
                'lat' => $this->restaurant->lat,
                'lng' => $this->restaurant->lng,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
