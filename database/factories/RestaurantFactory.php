<?php

namespace Database\Factories;

use App\Models\City;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Restaurant> */
class RestaurantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'city_id' => City::factory(),
            'lat' => fake()->latitude(41.2, 41.4),
            'lng' => fake()->longitude(69.1, 69.4),
            'phone' => '+9987' . fake()->numberBetween(1_000_000, 9_999_999),
            'logo_url' => null,
            'avg_prep_time_min' => fake()->numberBetween(15, 40),
            'delivery_radius_km' => fake()->randomElement([3, 5, 7, 10]),
            'min_order_amount' => fake()->randomElement([0, 3_000_000, 5_000_000]), // tiyin
            'delivery_fee' => fake()->randomElement([0, 1_000_000, 1_500_000]),     // tiyin
            'is_open' => true,
            'work_hours' => [
                'mon' => [['09:00', '23:00']],
                'tue' => [['09:00', '23:00']],
                'wed' => [['09:00', '23:00']],
                'thu' => [['09:00', '23:00']],
                'fri' => [['09:00', '23:00']],
                'sat' => [['10:00', '23:00']],
                'sun' => [['10:00', '23:00']],
            ],
            'pos_type' => 'manual',
            'printer_host' => null,
            'printer_port' => 9100,
            'pos_credentials' => null,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn () => ['is_open' => false]);
    }

    public function escpos(string $host = '192.168.1.50'): static
    {
        return $this->state(fn () => ['pos_type' => 'escpos', 'printer_host' => $host]);
    }
}
