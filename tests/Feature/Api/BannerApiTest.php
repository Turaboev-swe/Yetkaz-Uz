<?php

namespace Tests\Feature\Api;

use App\Models\Banner;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTelegramInitData;
use Tests\TestCase;

/** GET /api/banners — Mini App bosh sahifa karuseli. */
class BannerApiTest extends TestCase
{
    use InteractsWithTelegramInitData;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindInitDataValidator();
        Carbon::setTestNow(Carbon::parse('2026-10-04 12:00', 'Asia/Tashkent'));

        $this->user = User::factory()->create(['telegram_id' => 555001]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fetch(): TestResponse
    {
        return $this->getJson('/api/banners', $this->initDataHeaders($this->signedInitData(['id' => $this->user->telegram_id])));
    }

    public function test_requires_telegram_init_data(): void
    {
        $this->getJson('/api/banners')->assertUnauthorized();
    }

    public function test_returns_live_banners_in_sort_order_without_the_internal_title(): void
    {
        $restaurant = Restaurant::factory()->create();
        $second = Banner::factory()->create(['sort_order' => 2, 'image_path' => 'banners/b.jpg', 'title' => 'Ichki nom']);
        $first = Banner::factory()->forRestaurant($restaurant)->create(['sort_order' => 1, 'image_path' => 'banners/a.webp']);

        $this->fetch()
            ->assertOk()
            ->assertExactJson(['data' => [
                ['id' => $first->id, 'image_url' => '/storage/banners/a.webp', 'target_type' => 'restaurant', 'restaurant_id' => $restaurant->id],
                ['id' => $second->id, 'image_url' => '/storage/banners/b.jpg', 'target_type' => 'none', 'restaurant_id' => null],
            ]])
            ->assertDontSee('Ichki nom');
    }

    public function test_inactive_expired_and_not_yet_started_banners_are_not_returned(): void
    {
        $live = Banner::factory()->create();
        Banner::factory()->inactive()->create();
        Banner::factory()->create(['starts_at' => now()->subDays(3), 'ends_at' => now()->subMinute()]);
        Banner::factory()->create(['starts_at' => now()->addMinute()]);

        $this->fetch()->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $live->id);
    }

    public function test_banner_of_a_closed_restaurant_is_hidden(): void
    {
        $closed = Restaurant::factory()->create(['is_open' => false]);
        Banner::factory()->forRestaurant($closed)->create();

        $this->fetch()->assertOk()->assertExactJson(['data' => []]);

        $closed->update(['is_open' => true]);

        $this->fetch()->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_empty_list_when_there_are_no_banners(): void
    {
        $this->fetch()->assertOk()->assertExactJson(['data' => []]);
    }
}
