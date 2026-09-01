<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class KitchenOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $snap = $this->address_snapshot ?? [];

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'delivery_type' => $this->delivery_type->value,
            'created_at' => $this->created_at?->toIso8601String(),
            'eta_minutes' => $this->eta_minutes,

            'customer' => [
                'name' => $this->user?->full_name,
                'phone' => $this->user?->phone,
                'username' => $this->user?->username,
            ],

            'address' => $this->delivery_type->isPickup() ? null : [
                'text' => trim(($snap['address_text'] ?? '').' · '.($snap['district'] ?? ''), ' ·'),
                'entrance' => $snap['entrance'] ?? null,
                'floor' => $snap['floor'] ?? null,
                'apartment' => $snap['apartment'] ?? null,
                'lat' => $snap['lat'] ?? null,
                'lng' => $snap['lng'] ?? null,
            ],

            'items' => $this->items, // [{product_id, name, price, qty, prep, note}]
            'note' => $this->note,

            'subtotal' => $this->subtotal,
            'delivery_fee' => $this->delivery_fee,
            'total' => $this->total,
        ];
    }
}
