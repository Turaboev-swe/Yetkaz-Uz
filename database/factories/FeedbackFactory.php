<?php

namespace Database\Factories;

use App\Enums\FeedbackType;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Feedback> */
class FeedbackFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => fake()->randomElement(FeedbackType::cases()),
            'message' => fake()->sentence(12),
        ];
    }

    public function suggestion(): static
    {
        return $this->state(fn () => ['type' => FeedbackType::Suggestion]);
    }

    public function complaint(): static
    {
        return $this->state(fn () => ['type' => FeedbackType::Complaint]);
    }
}
