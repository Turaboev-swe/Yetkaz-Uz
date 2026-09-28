<?php

namespace Database\Factories;

use App\Enums\DiscountType;
use App\Models\PromoCode;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Diqqat: standart holatda kod HECH QAYSI restoranga biriktirilmaydi — demak
 * hech qayerda ishlamaydi (qoida: "barcha restoranlar" varianti yo'q). Qo'llash
 * uchun ->at($restaurant, ...) ishlating.
 *
 * @extends Factory<PromoCode>
 */
class PromoCodeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('PROMO##??')),
            'discount_type' => DiscountType::Percent,
            'discount_value' => 20,
            'min_order_amount' => null,
            'restaurant_share_percent' => 50, // baza standarti bilan bir xil — yarmini restoran, yarmini platforma
            'per_user_limit' => 1,            // baza standarti bilan bir xil — har mijoz bir marta
            'total_usage_limit' => null,
            'is_active' => true,
            'starts_at' => null,
            'ends_at' => null,
        ];
    }

    /** Kod ishlaydigan restoranlar (pivot). */
    public function at(Restaurant|int ...$restaurants): static
    {
        return $this->afterCreating(fn (PromoCode $promo) => $promo->restaurants()->attach(
            array_map(fn (Restaurant|int $r) => $r instanceof Restaurant ? $r->id : $r, $restaurants),
        ));
    }

    public function percent(int $value): static
    {
        return $this->state(fn () => ['discount_type' => DiscountType::Percent, 'discount_value' => $value]);
    }

    /** @param  int  $tiyin  Belgilangan chegirma summasi, tiyinда. */
    public function fixed(int $tiyin): static
    {
        return $this->state(fn () => ['discount_type' => DiscountType::Fixed, 'discount_value' => $tiyin]);
    }

    /** @param  int  $tiyin  Taomlar summasi uchun minimal chegara, tiyinда. */
    public function minOrder(int $tiyin): static
    {
        return $this->state(fn () => ['min_order_amount' => $tiyin]);
    }

    public function unlimitedPerUser(): static
    {
        return $this->state(fn () => ['per_user_limit' => null]);
    }

    public function restaurantShare(int $percent): static
    {
        return $this->state(fn () => ['restaurant_share_percent' => $percent]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
