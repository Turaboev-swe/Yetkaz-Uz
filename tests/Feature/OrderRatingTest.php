<?php

namespace Tests\Feature;

use App\Enums\DeliveryType;
use App\Enums\OrderStatus;
use App\Jobs\RequestOrderRating;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Ordering\OrderStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\UpdateType;
use Tests\TestCase;

class OrderRatingTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER_TG = 424242;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        $this->owner = User::factory()->create([
            'telegram_id' => self::OWNER_TG,
            'profile_completed' => true,
            'language' => 'uz',
        ]);
    }

    private function deliveredOrder(array $attrs = []): Order
    {
        return Order::factory()
            ->for($this->owner)
            ->for(Restaurant::factory())
            ->create(array_replace([
                'status' => OrderStatus::Delivered,
                'delivery_type' => DeliveryType::Delivery,
                'delivered_at' => now(),
            ], $attrs));
    }

    private function click(int $chatId, string $data): Nutgram
    {
        $bot = app(Nutgram::class);
        $bot->hearUpdateType(UpdateType::CALLBACK_QUERY, [
            'from' => ['id' => $chatId, 'first_name' => 'X'],
            'data' => $data,
            'message' => [
                'message_id' => 77,
                'date' => 1703892479,
                'chat' => ['id' => $chatId, 'type' => 'private'],
            ],
        ])->reply();

        return $bot;
    }

    // --- Job ---------------------------------------------------------------

    public function test_rating_request_is_queued_15_minutes_after_delivery(): void
    {
        Queue::fake();

        $order = Order::factory()->for($this->owner)->for(Restaurant::factory())->create([
            'status' => OrderStatus::OnTheWay,
            'delivery_type' => DeliveryType::Delivery,
        ]);

        app(OrderStatusService::class)->advance($order, 'test');

        Queue::assertPushed(RequestOrderRating::class, function (RequestOrderRating $job) use ($order) {
            return $job->orderId === $order->id
                && $job->delay !== null
                && $job->delay->getTimestamp() >= now()->addMinutes(14)->getTimestamp();
        });
    }

    public function test_job_sends_message_with_five_star_buttons(): void
    {
        $order = $this->deliveredOrder();

        $bot = app(Nutgram::class);
        (new RequestOrderRating($order->id))->handle($bot);

        $bot->assertCalled('sendMessage');
        $bot->assertRaw(function ($request) use ($order) {
            $body = (string) $request->getBody();

            return str_contains($body, $order->order_number)
                && str_contains($body, 'rate:'.$order->id.':1')
                && str_contains($body, 'rate:'.$order->id.':5');
        });
    }

    public function test_job_skips_when_already_rated(): void
    {
        $order = $this->deliveredOrder(['rating' => 4, 'rated_at' => now()]);

        $bot = app(Nutgram::class);
        (new RequestOrderRating($order->id))->handle($bot);

        $bot->assertCalled('sendMessage', 0);
    }

    // --- Yulduzcha bosish -------------------------------------------------

    public function test_star_press_saves_rating(): void
    {
        $order = $this->deliveredOrder();

        $bot = $this->click(self::OWNER_TG, "rate:{$order->id}:4");

        $order->refresh();
        $this->assertSame(4, $order->rating);
        $this->assertNotNull($order->rated_at);
        $bot->assertCalled('answerCallbackQuery');
        $bot->assertCalled('editMessageText');
    }

    public function test_only_the_order_owner_can_rate(): void
    {
        $order = $this->deliveredOrder();

        $bot = $this->click(999111, "rate:{$order->id}:5");

        $this->assertNull($order->refresh()->rating);
        $bot->assertCalled('answerCallbackQuery');
        $bot->assertCalled('editMessageText', 0);
    }

    public function test_re_rating_is_rejected(): void
    {
        $order = $this->deliveredOrder(['rating' => 5, 'rated_at' => now()]);

        $bot = $this->click(self::OWNER_TG, "rate:{$order->id}:2");

        $this->assertSame(5, $order->refresh()->rating);
        $bot->assertCalled('answerCallbackQuery');
        $bot->assertCalled('editMessageText', 0);
    }

    // --- Izoh -----------------------------------------------------------

    public function test_leave_comment_saves_the_next_text_message(): void
    {
        $order = $this->deliveredOrder(['rating' => 5, 'rated_at' => now()]);

        $bot = app(Nutgram::class);
        $bot->willStartConversation();

        $bot->hearUpdateType(UpdateType::CALLBACK_QUERY, [
            'from' => ['id' => self::OWNER_TG, 'first_name' => 'X'],
            'data' => "ratefu:{$order->id}:c",
            'message' => ['message_id' => 77, 'date' => 1703892479, 'chat' => ['id' => self::OWNER_TG, 'type' => 'private']],
        ])->reply();
        // 0: answerCallbackQuery, 1: editMessageReplyMarkup, 2: suhbat sendMessage
        $bot->assertActiveConversation();
        $bot->assertReplyText(__('messages.rating.ask_comment'), 2);

        $bot->hearMessage([
            'from' => ['id' => self::OWNER_TG, 'first_name' => 'X'],
            'text' => 'Hammasi zo\'r edi, rahmat!',
        ])->reply();

        $this->assertSame('Hammasi zo\'r edi, rahmat!', $order->refresh()->rating_comment);
        $bot->assertReplyText(__('messages.rating.comment_saved'));
        $bot->assertNoConversation();
    }

    public function test_conversation_state_is_cleared_after_the_comment(): void
    {
        $order = $this->deliveredOrder(['rating' => 3, 'rated_at' => now()]);

        $bot = app(Nutgram::class);
        $bot->willStartConversation();

        $bot->hearUpdateType(UpdateType::CALLBACK_QUERY, [
            'from' => ['id' => self::OWNER_TG, 'first_name' => 'X'],
            'data' => "ratefu:{$order->id}:c",
            'message' => ['message_id' => 77, 'date' => 1703892479, 'chat' => ['id' => self::OWNER_TG, 'type' => 'private']],
        ])->reply();

        $bot->hearMessage(['from' => ['id' => self::OWNER_TG, 'first_name' => 'X'], 'text' => 'Birinchi izoh'])->reply();
        $bot->hearMessage(['from' => ['id' => self::OWNER_TG, 'first_name' => 'X'], 'text' => 'Ikkinchi xabar'])->reply();

        // Ikkinchi xabar izoh sifatida SAQLANMAYDI.
        $this->assertSame('Birinchi izoh', $order->refresh()->rating_comment);
        $bot->assertNoConversation();
    }

    public function test_no_thanks_finishes_without_a_comment(): void
    {
        $order = $this->deliveredOrder(['rating' => 4, 'rated_at' => now()]);

        $bot = $this->click(self::OWNER_TG, "ratefu:{$order->id}:s");

        $this->assertNull($order->refresh()->rating_comment);
        $bot->assertCalled('answerCallbackQuery');
        $bot->assertCalled('editMessageReplyMarkup');
    }
}
