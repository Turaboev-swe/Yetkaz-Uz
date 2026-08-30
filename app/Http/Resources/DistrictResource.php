<?php

namespace App\Http\Resources;

use App\Models\District;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin District */
class DistrictResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'region_id' => $this->region_id,
            'name' => $this->name,
            // Faqat xaritani markazlashtirish uchun — masofa hisobiga aloqasi yo'q.
            'center_lat' => $this->center_lat,
            'center_lng' => $this->center_lng,
            'region' => $this->whenLoaded('region', fn () => $this->region?->name),
        ];
    }
}
