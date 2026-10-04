<?php

namespace App\Http\Resources;

use App\Models\Banner;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Banner — `title` ichki nom, mijozga yuborilmaydi. */
class BannerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'image_url' => Media::url($this->image_path),
            'target_type' => $this->target_type->value,
            'restaurant_id' => $this->restaurant_id,
        ];
    }
}
