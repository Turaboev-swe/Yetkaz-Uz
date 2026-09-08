<?php

namespace App\Http\Resources;

use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Address */
class AddressResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'address_text' => $this->address_text,
            // Mijozga ko'rsatiladigan manzil — hech qachon bo'sh yoki xom koordinata.
            'resolved_address' => $this->resource->displayAddress(),
            'district_id' => $this->district_id,
            // Doim o'zbekcha (districts jadvalidan).
            'district' => $this->whenLoaded('district', fn () => $this->district?->name),
            'entrance' => $this->entrance,
            'floor' => $this->floor,
            'apartment' => $this->apartment,
            'note' => $this->note,
            'is_default' => $this->is_default,
        ];
    }
}
