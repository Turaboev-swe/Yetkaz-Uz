<?php

namespace Database\Factories;

use App\Enums\BroadcastAudience;
use App\Models\Broadcast;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Broadcast> */
class BroadcastFactory extends Factory
{
    public function definition(): array
    {
        return [
            'message' => fake()->sentence(),
            'image_path' => null,
            'audience_type' => BroadcastAudience::All,
            'district_ids' => null,
            'sent_count' => 0,
            'failed_count' => 0,
            'created_by' => null,
        ];
    }

    public function forDistricts(array $districtIds): static
    {
        return $this->state(fn () => [
            'audience_type' => BroadcastAudience::District,
            'district_ids' => array_values($districtIds),
        ]);
    }
}
