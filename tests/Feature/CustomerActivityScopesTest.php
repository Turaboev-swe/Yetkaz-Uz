<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * User::activeSince / inactiveFor / returning — /admin "Mijozlar" faollik
 * filtri va dashboard statistikasi shu scope'larga tayanadi.
 *
 * Ta'rif: "faol mijoz" = tanlangan davr ichida kamida bitta
 * `status = delivered` buyurtma. Bekor qilingan va boshqa statuslar
 * hisobga olinmaydi. Chegaralar Asia/Tashkent bo'yicha (ReportPeriod::trailing).
 */
class CustomerActivityScopesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-21 12:00:00', 'Asia/Tashkent'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function customerWithDeliveredOrder(Carbon|string $deliveredAt): User
    {
        $user = User::factory()->create();
        Order::factory()->for($user)->delivered($deliveredAt)->create();

        return $user;
    }

    // --- activeSince ---

    public function test_a_delivered_order_within_the_window_makes_the_customer_active(): void
    {
        $recent = $this->customerWithDeliveredOrder(now()->subDays(5));
        $old = $this->customerWithDeliveredOrder(now()->subDays(40));

        $active = User::query()->activeSince(30)->pluck('id');

        $this->assertTrue($active->contains($recent->id));
        $this->assertFalse($active->contains($old->id));
    }

    public function test_cancelled_orders_do_not_count_as_activity(): void
    {
        $user = User::factory()->create();
        Order::factory()->for($user)->cancelled(now()->subDay())->create();

        $this->assertFalse(User::query()->activeSince(30)->pluck('id')->contains($user->id));
    }

    public function test_other_non_final_statuses_do_not_count_as_activity(): void
    {
        $user = User::factory()->create();
        Order::factory()->for($user)->create(['status' => OrderStatus::Preparing, 'created_at' => now()->subHour()]);

        $this->assertFalse(User::query()->activeSince(30)->pluck('id')->contains($user->id));
    }

    public function test_7_30_90_day_windows_are_independent(): void
    {
        $u7 = $this->customerWithDeliveredOrder(now()->subDays(3));
        $u30 = $this->customerWithDeliveredOrder(now()->subDays(20));
        $u90 = $this->customerWithDeliveredOrder(now()->subDays(60));

        $active7 = User::query()->activeSince(7)->pluck('id');
        $active30 = User::query()->activeSince(30)->pluck('id');
        $active90 = User::query()->activeSince(90)->pluck('id');

        $this->assertTrue($active7->contains($u7->id));
        $this->assertFalse($active7->contains($u30->id));

        $this->assertTrue($active30->contains($u7->id));
        $this->assertTrue($active30->contains($u30->id));
        $this->assertFalse($active30->contains($u90->id));

        $this->assertTrue($active90->contains($u7->id));
        $this->assertTrue($active90->contains($u30->id));
        $this->assertTrue($active90->contains($u90->id));
    }

    public function test_boundary_is_computed_in_tashkent_time_not_utc(): void
    {
        // 2026-09-21 03:00 Toshkent = 2026-09-20 22:00 UTC. 7 kunlik chegara:
        // 2026-09-14 03:00 Toshkent = 2026-09-13 22:00 UTC. UTC kalendar kuni
        // (2026-09-13 00:00-23:59) bilan hisoblansa bu ikki mijoz aksincha chiqardi.
        Carbon::setTestNow(Carbon::parse('2026-09-21 03:00:00', 'Asia/Tashkent'));

        $justInside = $this->customerWithDeliveredOrder(Carbon::parse('2026-09-13 22:30:00', 'UTC'));
        $justOutside = $this->customerWithDeliveredOrder(Carbon::parse('2026-09-13 21:30:00', 'UTC'));

        $active = User::query()->activeSince(7)->pluck('id');

        $this->assertTrue($active->contains($justInside->id));
        $this->assertFalse($active->contains($justOutside->id));
    }

    // --- inactiveFor ---

    public function test_inactive_requires_a_past_delivered_order_but_none_recently(): void
    {
        $wentQuiet = $this->customerWithDeliveredOrder(now()->subDays(40));
        $stillActive = $this->customerWithDeliveredOrder(now()->subDays(5));
        $neverOrdered = User::factory()->create();

        $inactive = User::query()->inactiveFor(30)->pluck('id');

        $this->assertTrue($inactive->contains($wentQuiet->id));
        $this->assertFalse($inactive->contains($stillActive->id));
        $this->assertFalse($inactive->contains($neverOrdered->id), 'Umuman buyurtma bermagan mijoz "nofaol" emas.');
    }

    public function test_a_customer_with_an_old_delivery_and_a_recent_cancellation_is_still_inactive(): void
    {
        $user = User::factory()->create();
        Order::factory()->for($user)->delivered(now()->subDays(40))->create();
        Order::factory()->for($user)->cancelled(now()->subDay())->create();

        $this->assertTrue(User::query()->inactiveFor(30)->pluck('id')->contains($user->id));
    }

    // --- returning ---

    public function test_returning_requires_at_least_two_delivered_orders(): void
    {
        $returning = User::factory()->create();
        Order::factory()->for($returning)->delivered(now()->subDays(10))->create();
        Order::factory()->for($returning)->delivered(now()->subDays(2))->create();

        $oneTime = $this->customerWithDeliveredOrder(now()->subDays(2));

        $ids = User::query()->returning()->pluck('id');

        $this->assertTrue($ids->contains($returning->id));
        $this->assertFalse($ids->contains($oneTime->id));
    }

    public function test_returning_ignores_cancelled_orders(): void
    {
        $user = User::factory()->create();
        Order::factory()->for($user)->delivered(now()->subDays(10))->create();
        Order::factory()->for($user)->cancelled(now()->subDays(2))->create();

        $this->assertFalse(User::query()->returning()->pluck('id')->contains($user->id));
    }
}
