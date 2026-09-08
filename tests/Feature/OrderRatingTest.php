<?php

namespace Tests\Feature;

use App\Enums\DeliveryType;
use App\Enums\OrderStatus;
use App\Jobs\RequestOrderRating;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Ordering\OrderStatusService;
use App\Services\Ordering\PendingRatingStore;
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

    private const PROMPT_MSG = 555;

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

    /** So'rov yuborilgan holatni to'g'ridan-to'g'ri o'rnatadi (xabar id bilan). */
    private function pendingFor(Order $order): void
    {
        app(PendingRatingStore::class)->remember(self::OWNER_TG, $order->id, self::PROMPT_MSG);
    }

    private function clickStar(int $chatId, Order $order, int $star): Nutgram
    {
        $bot = app(Nutgram::class);
        $bot->hearUpdateType(UpdateType::CALLBACK_QUERY, [
            'from' => ['id' => $chatId, 'first_name' => 'X'],
            'data' => "rate:{$order->id}:{$star}",
            'message' => [
                'message_id' => self::PROMPT_MSG,
                'date' => 1703892479,
                'chat' => ['id' => $chatId, 'type' => 'private'],
            ],
        ])->reply();

        return $bot;
    }

    private function sendText(int $chatId, string $text): Nutgram
    {
        $bot = app(Nutgram::class);
        $bot->hearMessage([
            'from' => ['id' => $chatId, 'first_name' => 'X'],
            'text' => $text,
        ])->reply();

        return $bot;
    }

    // --- Job / so'rov -----------------------------------------------------

    public function test_request_is_dispatched_immediately_on_delivery(): void
    {
        Queue::fake();

        $order = Order::factory()->for($this->owner)->for(Restaurant::factory())->create([
            'status' => OrderStatus::OnTheWay,
            'delivery_type' => DeliveryType::Delivery,
        ]);

        app(OrderStatusService::class)->advance($order, 'test');

        Queue::assertPushed(RequestOrderRating::class, function (RequestOrderRating $job) use ($order) {
            return $job->orderId === $order->id && $job->delay === null;
        });
    }

    public function test_request_message_has_star_buttons_and_sets_pending_state(): void
    {
        $order = $this->deliveredOrder();

        $bot = app(Nutgram::class);
        (new RequestOrderRating($order->id))->handle($bot, app(PendingRatingStore::class));

        $bot->assertCalled('sendMessage');
        $bot->assertRaw(function ($request) use ($order) {
            $body = (string) $request->getBody();

            return str_contains($body, $order->order_number)
                && str_contains($body, 'rate:'.$order->id.':1')
                && str_contains($body, 'rate:'.$order->id.':5')
                && str_contains($body, '⭐️');
        });

        $pending = app(PendingRatingStore::class)->pending(self::OWNER_TG);
        $this->assertSame($order->id, $pending['order_id']);
    }

    public function test_job_skips_when_already_responded(): void
    {
        $order = $this->deliveredOrder(['rated_at' => now()]);

        $bot = app(Nutgram::class);
        (new RequestOrderRating($order->id))->handle($bot, app(PendingRatingStore::class));

        $bot->assertCalled('sendMessage', 0);
    }

    // --- (a) faqat yulduzcha ------------------------------------------

    public function test_star_only_saves_rating_and_updates_message(): void
    {
        $order = $this->deliveredOrder();
        $this->pendingFor($order);

        $bot = $this->clickStar(self::OWNER_TG, $order, 4);

        $order->refresh();
        $this->assertSame(4, $order->rating);
        $this->assertNull($order->rating_comment);
        $this->assertNotNull($order->rated_at);
        $bot->assertCalled('answerCallbackQuery');
        // 0: answerCallbackQuery, 1: editMessageText (joriy holat)
        $bot->assertRaw(fn ($r) => str_contains((string) $r->getBody(), 'Bahoyingiz uchun rahmat'), 1);
    }

    // --- (b) faqat matn --------------------------------------------

    public function test_plain_text_is_saved_as_comment_rating_stays_null(): void
    {
        $order = $this->deliveredOrder();
        $this->pendingFor($order);

        $bot = $this->sendText(self::OWNER_TG, 'Kuryer kech keldi, lekin taom issiq edi.');

        $order->refresh();
        $this->assertNull($order->rating);
        $this->assertSame('Kuryer kech keldi, lekin taom issiq edi.', $order->rating_comment);
        $this->assertNotNull($order->rated_at);
        // So'rov xabari (555) joriy holatga yangilanadi — hali yulduzchasiz.
        $bot->assertReply('editMessageText', ['message_id' => self::PROMPT_MSG]);
        $bot->assertRaw(fn ($r) => str_contains((string) $r->getBody(), 'Izohingiz uchun rahmat'));
    }

    // --- (c) yulduzcha, keyin matn — ikkalasi ham -----------------

    public function test_star_then_text_updates_message_to_both(): void
    {
        $order = $this->deliveredOrder();
        $this->pendingFor($order);

        $this->clickStar(self::OWNER_TG, $order, 5);
        $bot = $this->sendText(self::OWNER_TG, 'yaxshi');

        $order->refresh();
        $this->assertSame(5, $order->rating);
        $this->assertSame('yaxshi', $order->rating_comment);
        $bot->assertRaw(function ($r) {
            $body = (string) $r->getBody();

            return str_contains($body, 'yaxshi') && str_contains($body, 'Bahoyingiz va izohingiz uchun rahmat');
        });
    }

    // --- (d) matn, keyin yulduzcha — ikkalasi ham -----------------

    public function test_text_then_star_saves_both(): void
    {
        $order = $this->deliveredOrder();
        $this->pendingFor($order);

        $this->sendText(self::OWNER_TG, 'Hammasi joyida.');
        $this->clickStar(self::OWNER_TG, $order, 3);

        $order->refresh();
        $this->assertSame(3, $order->rating);
        $this->assertSame('Hammasi joyida.', $order->rating_comment);
    }

    // --- Holat eskirishi / yo'qligi -------------------------------

    public function test_expired_pending_state_ignores_plain_text(): void
    {
        $order = $this->deliveredOrder();
        $this->pendingFor($order);

        $this->travel(25)->hours();

        $bot = $this->sendText(self::OWNER_TG, 'Bu endi izoh emas');

        $this->assertNull($order->refresh()->rating_comment);
        $bot->assertReplyText(__('messages.main_menu.title'));
    }

    public function test_plain_text_without_pending_state_is_not_a_comment(): void
    {
        $bot = $this->sendText(self::OWNER_TG, 'Salom, bu shunchaki xabar');

        $bot->assertReplyText(__('messages.main_menu.title'));
    }

    public function test_another_users_text_does_not_touch_the_pending_order(): void
    {
        $order = $this->deliveredOrder();
        $this->pendingFor($order);

        $other = User::factory()->create(['telegram_id' => 909090, 'profile_completed' => true, 'language' => 'uz']);

        $bot = $this->sendText($other->telegram_id, 'Boshqa odam yozyapti');

        $this->assertNull($order->refresh()->rating_comment);
        $bot->assertReplyText(__('messages.main_menu.title'));
    }

    // --- Ruxsat / qayta baholash -----------------------------------

    public function test_only_the_order_owner_can_press_a_star(): void
    {
        $order = $this->deliveredOrder();
        $this->pendingFor($order);

        $bot = $this->clickStar(999111, $order, 5);

        $this->assertNull($order->refresh()->rating);
        $bot->assertCalled('answerCallbackQuery');
        $bot->assertCalled('editMessageText', 0);
    }

    public function test_re_pressing_a_star_is_rejected(): void
    {
        $order = $this->deliveredOrder(['rating' => 5, 'rated_at' => now()]);

        $bot = $this->clickStar(self::OWNER_TG, $order, 2);

        $this->assertSame(5, $order->refresh()->rating);
        $bot->assertCalled('editMessageText', 0);
    }

    // --- Menyu tugmasi holatni tugatadi --------------------------

    public function test_pressing_a_menu_button_clears_the_pending_state(): void
    {
        $order = $this->deliveredOrder();
        $this->pendingFor($order);

        $this->sendText(self::OWNER_TG, __('messages.main_menu.order'));      // menyu tugmasi
        $this->sendText(self::OWNER_TG, 'Endi bu izoh bo\'lmasligi kerak');

        $this->assertNull($order->refresh()->rating_comment);
    }
}
