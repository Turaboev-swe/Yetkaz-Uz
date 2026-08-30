<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'telegram_id' => $this->telegram_id,
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'language' => $this->language,
            'profile_completed' => $this->profile_completed,
            'addresses' => AddressResource::collection($this->whenLoaded('addresses')),
        ];
    }
}
