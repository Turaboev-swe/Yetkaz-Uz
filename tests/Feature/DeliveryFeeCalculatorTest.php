<?php

namespace Tests\Feature;

use App\Enums\DeliveryType;
use App\Models\District;
use App\Models\Restaurant;
use App\Services\Delivery\DeliveryFeeCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Masofaga qarab yetkazish narxi (Claude.md / topshiriq spetsifikatsiyasi):
 *
 *   pickup                                                      -> 0
 *   free_delivery_radius_km TO'LDIRILGAN va masofa <= shu radius -> 0
 *   price_per_km TO'LDIRILGAN                                    -> round(masofa_km * price_per_km)
 *   aks holda                                                    -> restaurant.delivery_fee (ZAXIRA)
 */
class DeliveryFeeCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private function calculator(): DeliveryFeeCalculator
    {
        return app(DeliveryFeeCalculator::class);
    }

    private function restaurant(array $overrides = []): Restaurant
    {
        return Restaurant::factory()->for(District::factory())->create(array_merge([
            'delivery_fee' => 1_500_000, // 15 000 so'm — eski qat'iy narx (zaxira)
            'free_delivery_radius_km' => null,
            'price_per_km' => null,
        ], $overrides));
    }

    public function test_pickup_is_always_free_regardless_of_distance_fields(): void
    {
        $r = $this->restaurant(['free_delivery_radius_km' => 2, 'price_per_km' => 100_000]);

        $fee = $this->calculator()->calculate($r, DeliveryType::Pickup, 10.0);

        $this->assertSame(0, $fee);
    }

    public function test_within_free_radius_is_free(): void
    {
        $r = $this->restaurant(['free_delivery_radius_km' => 3, 'price_per_km' => 200_000]);

        $fee = $this->calculator()->calculate($r, DeliveryType::Delivery, 2.5);

        $this->assertSame(0, $fee);
    }

    public function test_exactly_at_the_free_radius_boundary_is_free(): void
    {
        $r = $this->restaurant(['free_delivery_radius_km' => 3, 'price_per_km' => 200_000]);

        $fee = $this->calculator()->calculate($r, DeliveryType::Delivery, 3.0);

        $this->assertSame(0, $fee);
    }

    public function test_beyond_free_radius_charges_price_per_km_for_the_whole_distance(): void
    {
        $r = $this->restaurant(['free_delivery_radius_km' => 2, 'price_per_km' => 100_000]); // 1000 so'm/km

        $fee = $this->calculator()->calculate($r, DeliveryType::Delivery, 5.0);

        // 5 km (chegirmasiz, butun masofa) * 100 000 tiyin = 500 000 tiyin.
        $this->assertSame(500_000, $fee);
    }

    public function test_price_per_km_without_a_free_radius_applies_at_any_distance(): void
    {
        $r = $this->restaurant(['free_delivery_radius_km' => null, 'price_per_km' => 100_000]);

        $fee = $this->calculator()->calculate($r, DeliveryType::Delivery, 0.5);

        $this->assertSame(50_000, $fee);
    }

    /**
     * free_radius BOR, price_per_km YO'Q: radius ichida bepul, tashqarisida esa
     * km narxi bo'lmagani uchun ESKI qat'iy delivery_fee ishlatiladi. Bu —
     * spetsifikatsiya pseudokodidan to'g'ridan-to'g'ri kelib chiqadigan xatti-harakat.
     */
    public function test_free_radius_without_price_per_km_falls_back_to_the_flat_fee_beyond_radius(): void
    {
        $r = $this->restaurant(['free_delivery_radius_km' => 2, 'price_per_km' => null, 'delivery_fee' => 1_200_000]);

        $fee = $this->calculator()->calculate($r, DeliveryType::Delivery, 5.0);

        $this->assertSame(1_200_000, $fee);
    }

    public function test_neither_field_set_falls_back_to_the_flat_fee_no_regression(): void
    {
        $r = $this->restaurant(['delivery_fee' => 1_000_000]);

        $fee = $this->calculator()->calculate($r, DeliveryType::Delivery, 7.3);

        $this->assertSame(1_000_000, $fee);
    }

    /**
     * Butun so'mga (eng yaqiniga). Tiyin aniqligida total kasrli so'm bo'lib,
     * Mini App (Math.round) va PHP (intdiv) 1 so'm farqli summa ko'rsatardi.
     */
    public function test_rounds_to_the_nearest_whole_som(): void
    {
        $r = $this->restaurant(['price_per_km' => 150_000]); // 1 500 so'm/km

        // 3,271 km × 1 500 = 4 906,50 so'm -> 4 907 so'm (yuqoriga)
        $this->assertSame(4_907_00, $this->calculator()->calculate($r, DeliveryType::Delivery, 3.271));
        // 3,2701 km × 1 500 = 4 905,15 so'm -> 4 905 so'm (pastga)
        $this->assertSame(4_905_00, $this->calculator()->calculate($r, DeliveryType::Delivery, 3.2701));
    }

    public function test_every_branch_returns_a_whole_som(): void
    {
        $perKm = $this->restaurant(['price_per_km' => 333_333]);
        $flat = $this->restaurant(['price_per_km' => null, 'free_delivery_radius_km' => null, 'delivery_fee' => 1_234_567]);

        foreach ([0.37, 1.5, 2.718, 7.77, 12.3456] as $km) {
            $this->assertSame(0, $this->calculator()->calculate($perKm, DeliveryType::Delivery, $km) % 100, "{$km} km");
        }
        // Qat'iy narx ham (masalan 12 345,67 so'm kiritilgan bo'lsa) -> 12 346 so'm.
        $this->assertSame(12_346_00, $this->calculator()->calculate($flat, DeliveryType::Delivery, 5.0));
    }

    public function test_null_distance_is_treated_as_zero_km(): void
    {
        $r = $this->restaurant(['price_per_km' => 500_000]);

        $fee = $this->calculator()->calculate($r, DeliveryType::Delivery, null);

        $this->assertSame(0, $fee);
    }
}
