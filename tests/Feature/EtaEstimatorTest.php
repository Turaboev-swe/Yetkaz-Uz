<?php

namespace Tests\Feature;

use App\Enums\DeliveryType;
use App\Enums\OrderStatus;
use App\Models\District;
use App\Models\Order;
use App\Models\Restaurant;
use App\Services\Eta\EtaEstimator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ETA = pishirish + navbat_jarimasi + kuryer_kutish + yol_vaqti + bufer  (Claude.md)
 */
class EtaEstimatorTest extends TestCase
{
    use RefreshDatabase;

    private function restaurant(): Restaurant
    {
        return Restaurant::factory()->for(District::factory())->create(['avg_prep_time_min' => 15]);
    }

    private function estimator(): EtaEstimator
    {
        return app(EtaEstimator::class);
    }

    /** Off-peak (14:00): tezlik 28 km/soat. */
    private function offPeak(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-02 14:00', 'Asia/Tashkent');
    }

    public function test_pickup_is_prep_plus_buffer_only(): void
    {
        $r = $this->restaurant();

        $eta = $this->estimator()->estimate($r, DeliveryType::Pickup, straightLineKm: 5.0, maxPrepMin: 22, at: $this->offPeak());

        // 22 (prep) + 0 (navbat) + 0 (kuryer) + 0 (yo'l) + 5 (bufer)
        $this->assertSame(27, $eta->minutes);
    }

    public function test_delivery_off_peak_adds_travel_and_courier(): void
    {
        $r = $this->restaurant();

        // masofa 5 km -> yo'l 5*1.35 = 6.75 km / 28 * 60 = 14.46 -> 14
        // 20 (prep) + 0 + 5 (kuryer) + 14 (yo'l) + 5 (bufer) = 44
        $eta = $this->estimator()->estimate($r, DeliveryType::Delivery, straightLineKm: 5.0, maxPrepMin: 20, at: $this->offPeak());

        $this->assertSame(44, $eta->minutes);
    }

    public function test_peak_hours_use_slower_speed(): void
    {
        $r = $this->restaurant();
        $peak = CarbonImmutable::parse('2026-09-02 18:30', 'Asia/Tashkent'); // 17:00–20:00

        // 5*1.35 = 6.75 / 22 * 60 = 18.4 -> 18;  20 + 0 + 5 + 18 + 5 = 48
        $eta = $this->estimator()->estimate($r, DeliveryType::Delivery, straightLineKm: 5.0, maxPrepMin: 20, at: $peak);

        $this->assertSame(48, $eta->minutes);
    }

    public function test_queue_penalty_grows_with_active_orders_capped_at_20(): void
    {
        $r = $this->restaurant();
        Order::factory()->count(3)->for($r)->create(['status' => OrderStatus::Preparing]);
        Order::factory()->for($r)->create(['status' => OrderStatus::Delivered]); // faol emas

        // 3 faol * 2 = 6;  15 (prep) + 6 + 0 + 0 + 5 = 26  (pickup)
        $eta = $this->estimator()->estimate($r, DeliveryType::Pickup, straightLineKm: null, maxPrepMin: 15, at: $this->offPeak());
        $this->assertSame(26, $eta->minutes);

        Order::factory()->count(15)->for($r)->create(['status' => OrderStatus::New]);
        // 18 faol * 2 = 36 -> 20 ga cheklanadi;  15 + 20 + 5 = 40
        $eta = $this->estimator()->estimate($r, DeliveryType::Pickup, straightLineKm: null, maxPrepMin: 15, at: $this->offPeak());
        $this->assertSame(40, $eta->minutes);
    }

    public function test_range_is_minus_5_plus_10_rounded_to_5(): void
    {
        $r = $this->restaurant();

        $eta = $this->estimator()->estimate($r, DeliveryType::Pickup, straightLineKm: null, maxPrepMin: 22, at: $this->offPeak());

        $this->assertSame(27, $eta->minutes);
        $this->assertSame(20, $eta->low);   // (27-5)=22 -> 20
        $this->assertSame(35, $eta->high);  // (27+10)=37 -> 35
    }
}
