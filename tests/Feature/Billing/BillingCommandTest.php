<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingPlan;
use App\Enums\OrderStatus;
use App\Models\District;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantInvoice;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** billing:generate-invoices buyrug'i va oylik rejalashtirish. */
class BillingCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function restaurant(array $overrides = []): Restaurant
    {
        return Restaurant::factory()->for(District::factory())->create(array_replace([
            'billing_plan' => BillingPlan::Percent,
            'commission_rate_bp' => 500,
            'monthly_fee' => 10_000_000,
            'is_test' => false,
        ], $overrides));
    }

    private function delivered(Restaurant $r, string $tashkentTime, int $subtotal): Order
    {
        return Order::factory()->forRestaurant($r)->create([
            'status' => OrderStatus::Delivered,
            'delivered_at' => Carbon::parse($tashkentTime, 'Asia/Tashkent')->utc(),
            'subtotal' => $subtotal,
            'delivery_fee' => 0,
            'discount_amount' => 0,
            'discount_restaurant_amount' => 0,
            'discount_platform_amount' => 0,
            'total' => $subtotal,
        ]);
    }

    public function test_command_generates_for_the_given_period_and_skips_unplanned_restaurants(): void
    {
        $a = $this->restaurant();
        $b = $this->restaurant(['billing_plan' => BillingPlan::Fixed]);
        $this->restaurant(['billing_plan' => null]);
        $this->restaurant(['is_test' => true]);
        $this->delivered($a, '2026-11-05 12:00', 5_000_000);

        $this->artisan('billing:generate-invoices', ['--period' => '2026-11'])
            ->expectsOutputToContain('yaratildi 2')
            ->assertSuccessful();
        $this->artisan('billing:generate-invoices', ['--period' => '2026-11'])
            ->expectsOutputToContain('yangilandi 2')
            ->assertSuccessful();

        $this->assertSame(2, RestaurantInvoice::count());
        $this->assertEqualsCanonicalizing([$a->id, $b->id], RestaurantInvoice::pluck('restaurant_id')->all());
    }

    public function test_command_rerun_keeps_the_invoice_tariff_snapshot(): void
    {
        $r = $this->restaurant();
        $this->delivered($r, '2026-11-05 12:00', 5_000_000);
        $this->artisan('billing:generate-invoices', ['--period' => '2026-11'])->assertSuccessful();

        $r->update(['commission_rate_bp' => 1000]);
        $this->artisan('billing:generate-invoices', ['--period' => '2026-11'])->assertSuccessful();

        $this->assertSame([500, 250_000], [RestaurantInvoice::first()->rate_bp, RestaurantInvoice::first()->amount_due]);
    }

    public function test_command_defaults_to_the_previous_month_in_tashkent_time(): void
    {
        $r = $this->restaurant();
        // Toshkent 2026-12-01 06:00 = UTC 01:00.
        Carbon::setTestNow(Carbon::parse('2026-12-01 06:00', 'Asia/Tashkent'));
        $this->delivered($r, '2026-11-20 12:00', 5_000_000);

        $this->artisan('billing:generate-invoices')->assertSuccessful();

        $this->assertSame('2026-11-01', RestaurantInvoice::first()->period->format('Y-m-d'));
    }

    public function test_command_rejects_a_malformed_period(): void
    {
        $this->artisan('billing:generate-invoices', ['--period' => '2026-13'])->assertFailed();
        $this->artisan('billing:generate-invoices', ['--period' => 'noyabr'])->assertFailed();
        $this->assertSame(0, RestaurantInvoice::count());
    }

    public function test_command_is_scheduled_for_the_first_of_each_month_at_six_tashkent(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'billing:generate-invoices'));

        $this->assertNotNull($event, 'buyruq rejalashtirilmagan');
        $this->assertSame('0 6 1 * *', $event->expression);
        $this->assertSame('Asia/Tashkent', (string) $event->timezone);
    }
}
