<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Jobs\RecalculateRestaurantRating;
use App\Models\Address;
use App\Models\District;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Delivery\RestaurantFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\UpdateType;
use Tests\Concerns\InteractsWithTelegramInitData;
use Tests\TestCase;

class RestaurantRatingTest extends TestCase
{
    use InteractsWithTelegramInitData;
    use RefreshDatabase;

    private User $user;

    private Address $address;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindInitDataValidator();
        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00', 'Asia/Tashkent'));

        $this->user = User::factory()->create(['telegram_id' => 555000]);
        $this->address = Address::factory()->for($this->user)->default()->create(['lat' => 41.311, 'lng' => 69.279]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function alwaysOpen(): array
    {
        return array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], [['00:00', '23:59']]);
    }

    private function restaurant(array $attrs = []): Restaurant
    {
        return Restaurant::factory()->for(District::factory())->create(array_replace([
            'work_hours' => $this->alwaysOpen(),
            'is_open' => true,
            'lat' => 41.311,
            'lng' => 69.281,
            'delivery_radius_km' => 10,
        ], $attrs));
    }

    private function listJson()
    {
        return $this->getJson(
            '/api/restaurants?address_id='.$this->address->id,
            $this->initDataHeaders($this->signedInitData(['id' => $this->user->telegram_id])),
        );
    }

    // --- RestaurantResource: ko'rsatish sharti -------------------------

    public function test_rating_is_shown_when_threshold_met(): void
    {
        $this->restaurant(['name' => 'Yaxshi', 'cached_average_rating' => 4.6, 'cached_ratings_count' => 8]);

        $this->listJson()
            ->assertOk()
            ->assertJsonPath('data.0.average_rating', 4.6)
            ->assertJsonPath('data.0.ratings_count', 8);
    }

    public function test_rating_hidden_when_average_below_four(): void
    {
        $this->restaurant(['name' => 'Past ball', 'cached_average_rating' => 3.9, 'cached_ratings_count' => 20]);

        $this->listJson()
            ->assertOk()
            ->assertJsonPath('data.0.average_rating', null)
            ->assertJsonPath('data.0.ratings_count', null);
    }

    public function test_rating_hidden_when_too_few_ratings(): void
    {
        $this->restaurant(['name' => 'Kam baho', 'cached_average_rating' => 4.8, 'cached_ratings_count' => 3]);

        $this->listJson()
            ->assertOk()
            ->assertJsonPath('data.0.average_rating', null)
            ->assertJsonPath('data.0.ratings_count', null);
    }

    public function test_low_score_never_leaks_in_the_payload(): void
    {
        $this->restaurant(['name' => 'Yomon', 'cached_average_rating' => 2.1, 'cached_ratings_count' => 50]);

        $body = $this->listJson()->assertOk()->getContent();

        $this->assertStringNotContainsString('2.1', $body);
    }

    // --- Saralash ----------------------------------------------------

    public function test_rated_restaurants_rank_above_new_ones_by_rating(): void
    {
        // "Yangi" — eng yaqin, lekin reyting yo'q -> pastda
        $this->restaurant(['name' => 'Yangi yaqin', 'lat' => 41.311, 'lng' => 69.2795]);
        // Reytingli — uzoqroq, lekin tepada
        $this->restaurant(['name' => 'Reyting 4.5', 'lat' => 41.311, 'lng' => 69.290, 'cached_average_rating' => 4.5, 'cached_ratings_count' => 10]);
        $this->restaurant(['name' => 'Reyting 4.9', 'lat' => 41.311, 'lng' => 69.295, 'cached_average_rating' => 4.9, 'cached_ratings_count' => 10]);

        $this->listJson()
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Reyting 4.9')
            ->assertJsonPath('data.1.name', 'Reyting 4.5')
            ->assertJsonPath('data.2.name', 'Yangi yaqin');
    }

    public function test_finder_orders_rated_before_new(): void
    {
        $new = $this->restaurant(['name' => 'N', 'lng' => 69.2795]);
        $rated = $this->restaurant(['name' => 'R', 'lng' => 69.300, 'cached_average_rating' => 4.2, 'cached_ratings_count' => 6]);

        $result = app(RestaurantFinder::class)->deliveringTo($this->address->fresh());

        $this->assertSame([$rated->id, $new->id], $result->pluck('id')->all());
    }

    // --- Job: qayta hisoblash --------------------------------------

    public function test_recalculate_job_computes_average_and_count(): void
    {
        $restaurant = $this->restaurant();

        foreach ([5, 4, 5, 3, 5] as $stars) {
            Order::factory()->for($restaurant)->for(User::factory())->create([
                'status' => OrderStatus::Delivered,
                'rating' => $stars,
                'rated_at' => now(),
            ]);
        }
        // Baholanmagan buyurtma hisobga olinmaydi
        Order::factory()->for($restaurant)->for(User::factory())->create(['status' => OrderStatus::Delivered]);

        (new RecalculateRestaurantRating($restaurant->id))->handle();

        $restaurant->refresh();
        $this->assertSame(5, $restaurant->cached_ratings_count);
        $this->assertSame(4.4, $restaurant->cached_average_rating); // (5+4+5+3+5)/5 = 4.4
    }

    public function test_new_rating_via_bot_recalculates_the_cache(): void
    {
        $restaurant = $this->restaurant();

        foreach ([5, 5, 5, 4] as $stars) {
            Order::factory()->for($restaurant)->for(User::factory())->create([
                'status' => OrderStatus::Delivered, 'rating' => $stars, 'rated_at' => now(),
            ]);
        }
        $order = Order::factory()->for($restaurant)->for($this->user)->create([
            'status' => OrderStatus::Delivered, 'delivery_type' => 'delivery',
        ]);

        // Bot orqali 5-baho — QUEUE_CONNECTION=sync, job darhol ishlaydi
        $bot = app(Nutgram::class);
        $bot->hearUpdateType(UpdateType::CALLBACK_QUERY, [
            'from' => ['id' => $this->user->telegram_id, 'first_name' => 'X'],
            'data' => "rate:{$order->id}:5",
            'message' => ['message_id' => 5, 'date' => 1703892479, 'chat' => ['id' => $this->user->telegram_id, 'type' => 'private']],
        ])->reply();

        $restaurant->refresh();
        $this->assertSame(5, $restaurant->cached_ratings_count);
        $this->assertSame(4.8, $restaurant->cached_average_rating); // (5+5+5+4+5)/5 = 4.8
    }
}
