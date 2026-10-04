<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Restaurant;
use App\Services\Marketing\BannerFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * BannerFeed — mijozga hozir ko'rsatiladigan bannerlar. Muddat chegaralari
 * Toshkent vaqti bo'yicha: 1-oktabr 00:00 dan 23:59 gacha (Toshkent) — bazada
 * 30-sentabr 19:00 … 1-oktabr 18:59 UTC.
 */
class BannerFeedTest extends TestCase
{
    use RefreshDatabase;

    private function tashkent(string $time): Carbon
    {
        return Carbon::parse($time, 'Asia/Tashkent')->utc();
    }

    /** @return list<int> */
    private function idsAt(string $tashkent): array
    {
        return app(BannerFeed::class)->current($this->tashkent($tashkent))->pluck('id')->all();
    }

    public function test_period_boundaries_follow_tashkent_time(): void
    {
        $banner = Banner::factory()->create([
            'starts_at' => $this->tashkent('2026-10-01 00:00:00'),
            'ends_at' => $this->tashkent('2026-10-01 23:59:00'),
        ]);

        $this->assertSame('2026-09-30 19:00:00', $banner->getRawOriginal('starts_at'));

        $this->assertSame([], $this->idsAt('2026-09-30 23:59:59'));
        $this->assertSame([$banner->id], $this->idsAt('2026-10-01 00:00:00'));
        $this->assertSame([$banner->id], $this->idsAt('2026-10-01 12:00:00'));
        $this->assertSame([$banner->id], $this->idsAt('2026-10-01 23:59:00'));
        $this->assertSame([], $this->idsAt('2026-10-01 23:59:01'));
        $this->assertSame([], $this->idsAt('2026-10-02 00:00:00'));
    }

    public function test_banner_without_end_stays_visible(): void
    {
        $banner = Banner::factory()->create(['starts_at' => $this->tashkent('2026-10-01 00:00'), 'ends_at' => null]);

        $this->assertSame([$banner->id], $this->idsAt('2027-06-01 12:00'));
    }

    public function test_inactive_banner_is_never_returned(): void
    {
        Banner::factory()->inactive()->create(['starts_at' => $this->tashkent('2026-10-01 00:00')]);

        $this->assertSame([], $this->idsAt('2026-10-01 12:00'));
    }

    public function test_restaurant_banner_follows_the_restaurant_is_open_flag(): void
    {
        $restaurant = Restaurant::factory()->create(['is_open' => false]);
        $banner = Banner::factory()->forRestaurant($restaurant)->create(['starts_at' => $this->tashkent('2026-10-01 00:00')]);

        $this->assertSame([], $this->idsAt('2026-10-01 12:00'));

        $restaurant->update(['is_open' => true]);
        $this->assertSame([$banner->id], $this->idsAt('2026-10-01 12:00'));
    }

    public function test_ordered_by_sort_order_then_creation(): void
    {
        $start = ['starts_at' => $this->tashkent('2026-10-01 00:00')];
        $c = Banner::factory()->create(['sort_order' => 5] + $start);
        $a = Banner::factory()->create(['sort_order' => 1] + $start);
        $b = Banner::factory()->create(['sort_order' => 1] + $start);

        $this->assertSame([$a->id, $b->id, $c->id], $this->idsAt('2026-10-01 12:00'));
    }

    public function test_status_in_the_panel_matches_the_feed(): void
    {
        $now = $this->tashkent('2026-10-01 12:00');
        $closed = Restaurant::factory()->create(['is_open' => false]);

        $this->assertSame('live', Banner::factory()->make(['starts_at' => $now->copy()->subHour()])->statusAt($now));
        $this->assertSame('off', Banner::factory()->inactive()->make()->statusAt($now));
        $this->assertSame('scheduled', Banner::factory()->make(['starts_at' => $now->copy()->addHour()])->statusAt($now));
        $this->assertSame('expired', Banner::factory()->make(['starts_at' => $now->copy()->subDay(), 'ends_at' => $now->copy()->subSecond()])->statusAt($now));
        $this->assertSame('restaurant_closed', Banner::factory()->forRestaurant($closed)->make(['starts_at' => $now->copy()->subHour()])->statusAt($now));
    }
}
