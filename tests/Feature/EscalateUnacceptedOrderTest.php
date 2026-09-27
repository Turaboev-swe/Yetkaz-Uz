<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Jobs\EscalateUnacceptedOrder;
use App\Models\District;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Models\User;
use App\Notifications\NewKitchenOrderPushNotification;
use App\Telegram\Support\KitchenOrderMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use SergiX44\Nutgram\Nutgram;
use Tests\TestCase;

/**
 * Qabul qilinmagan buyurtma eslatmalari — vaqt oldinga surilib, har bosqich
 * job'i qo'lda ishga tushiriladi (navbat Queue::fake — keyingi bosqich
 * to'g'ri kechikish bilan qo'yilgani tekshiriladi).
 */
class EscalateUnacceptedOrderTest extends TestCase
{
    use RefreshDatabase;

    private const KITCHEN_CHAT = 1001;

    private const OWNER_CHAT = 1002;

    private const ADMIN_CHAT = 9001;

    private Carbon $placedAt;

    private Restaurant $restaurant;

    private Staff $kitchen;

    private Order $order;

    private Nutgram $bot;

    private int $historyOffset = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->placedAt = Carbon::parse('2026-09-27 09:00:00');
        Carbon::setTestNow($this->placedAt);
        Queue::fake();
        Notification::fake();

        $this->restaurant = Restaurant::factory()->for(District::factory())->create([
            'name' => 'Istiqlol Food',
            'phone' => '+998901112233',
        ]);
        $this->kitchen = Staff::factory()->kitchenStaff($this->restaurant)->create(['telegram_chat_id' => self::KITCHEN_CHAT]);
        $this->kitchen->updatePushSubscription('https://fcm.googleapis.com/fcm/send/k1', 'key', 'auth');
        Staff::factory()->owner($this->restaurant)->create(['telegram_chat_id' => self::OWNER_CHAT]);
        Staff::factory()->platformAdmin()->create(['telegram_chat_id' => self::ADMIN_CHAT]);

        // Boshqa restoran xodimi — hech qachon eslatma olmasligi kerak.
        Staff::factory()->kitchenStaff(Restaurant::factory()->for(District::factory())->create())
            ->create(['telegram_chat_id' => 5555]);

        $this->order = Order::factory()
            ->for($this->restaurant)
            ->for(User::factory()->state(['phone' => '+998935556677']))
            ->create(['status' => OrderStatus::New, 'order_number' => 'YT-100200']);

        $this->bot = app(Nutgram::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function runStageAt(int $stage, int $minutesAfterPlaced): void
    {
        Carbon::setTestNow($this->placedAt->copy()->addMinutes($minutesAfterPlaced));

        (new EscalateUnacceptedOrder($this->order->id, $stage))->handle($this->bot, app(KitchenOrderMessage::class));
    }

    /** @return list<array{chat_id: int, text: string, markup: string}> */
    private function sentMessages(): array
    {
        $messages = [];

        foreach (array_slice($this->bot->getRequestHistory(), $this->historyOffset) as $entry) {
            $request = $entry['request'];
            if (! str_ends_with((string) $request->getUri(), 'sendMessage')) {
                continue;
            }

            $body = (string) $request->getBody();
            $json = json_decode($body, true);
            if (! is_array($json)) {
                parse_str($body, $json);
            }

            $messages[] = [
                'chat_id' => (int) ($json['chat_id'] ?? 0),
                'text' => (string) ($json['text'] ?? ''),
                'markup' => is_array($json['reply_markup'] ?? null)
                    ? json_encode($json['reply_markup'])
                    : (string) ($json['reply_markup'] ?? ''),
            ];
        }

        return $messages;
    }

    /** @return list<array{chat_id: int, text: string, markup: string}> */
    private function messagesTo(int $chatId): array
    {
        return array_values(array_filter($this->sentMessages(), fn ($m) => $m['chat_id'] === $chatId));
    }

    /** Keyingi tekshiruvlar faqat shu nuqtadan keyingi so'rovlarni ko'radi. */
    private function resetBotHistory(): void
    {
        $this->historyOffset = count($this->bot->getRequestHistory());
    }

    public function test_three_stages_run_in_order_for_an_unaccepted_order(): void
    {
        // --- 2 daqiqa: barcha xodimlar + push ---
        $this->runStageAt(1, 2);

        $kitchen = $this->messagesTo(self::KITCHEN_CHAT);
        $this->assertCount(1, $kitchen);
        $this->assertStringContainsString('⏰ YT-100200 — 2 daqiqadan beri qabul qilinmadi!', $kitchen[0]['text']);
        $this->assertStringContainsString("kadv:{$this->order->id}:new", $kitchen[0]['markup']);
        $this->assertCount(1, $this->messagesTo(self::OWNER_CHAT));
        $this->assertCount(0, $this->messagesTo(self::ADMIN_CHAT));
        $this->assertCount(0, $this->messagesTo(5555));
        Notification::assertSentToTimes($this->kitchen, NewKitchenOrderPushNotification::class, 1);
        Queue::assertPushed(EscalateUnacceptedOrder::class, fn ($job) => $job->stage === 2
            && $job->delay->equalTo($this->placedAt->copy()->addMinutes(4)));

        // --- 4 daqiqa: yana hammaga, egasiga urg'u bilan ---
        $this->resetBotHistory();
        $this->runStageAt(2, 4);

        $this->assertStringContainsString('4 daqiqadan beri', $this->messagesTo(self::KITCHEN_CHAT)[0]['text']);
        $owner = $this->messagesTo(self::OWNER_CHAT);
        $this->assertCount(1, $owner);
        $this->assertStringContainsString('DIQQAT, restoran egasi!', $owner[0]['text']);
        $this->assertStringContainsString("kadv:{$this->order->id}:new", $owner[0]['markup']);
        $this->assertCount(0, $this->messagesTo(self::ADMIN_CHAT));
        Notification::assertSentToTimes($this->kitchen, NewKitchenOrderPushNotification::class, 2);
        Queue::assertPushed(EscalateUnacceptedOrder::class, fn ($job) => $job->stage === 3
            && $job->delay->equalTo($this->placedAt->copy()->addMinutes(7)));

        // --- 7 daqiqa: faqat platforma admini ---
        $this->resetBotHistory();
        $this->runStageAt(3, 7);

        $this->assertCount(0, $this->messagesTo(self::KITCHEN_CHAT));
        $this->assertCount(0, $this->messagesTo(self::OWNER_CHAT));
        $admin = $this->messagesTo(self::ADMIN_CHAT);
        $this->assertCount(1, $admin);
        foreach (['Istiqlol Food', 'YT-100200', '7 daqiqa', '+998901112233', '+998935556677'] as $expected) {
            $this->assertStringContainsString($expected, $admin[0]['text']);
        }
        Notification::assertSentToTimes($this->kitchen, NewKitchenOrderPushNotification::class, 2);
        Queue::assertNotPushed(EscalateUnacceptedOrder::class, fn ($job) => $job->stage === 4);
        $this->assertSame(3, $this->order->fresh()->escalation_stage);
    }

    public function test_accepting_after_stage_one_stops_all_further_reminders(): void
    {
        $this->runStageAt(1, 2);
        $this->order->update(['status' => OrderStatus::Accepted]);
        $this->resetBotHistory();

        $this->runStageAt(2, 4);
        $this->runStageAt(3, 7);

        $this->assertSame([], $this->sentMessages());
        Notification::assertSentToTimes($this->kitchen, NewKitchenOrderPushNotification::class, 1);
        Queue::assertNotPushed(EscalateUnacceptedOrder::class, fn ($job) => $job->stage === 3);
    }

    public function test_cancelled_order_gets_no_reminders(): void
    {
        $this->order->update(['status' => OrderStatus::Cancelled]);

        $this->runStageAt(1, 2);

        $this->assertSame([], $this->sentMessages());
        Notification::assertNothingSent();
        Queue::assertNotPushed(EscalateUnacceptedOrder::class);
    }

    public function test_admin_is_notified_only_at_the_last_stage_and_only_once(): void
    {
        $this->runStageAt(1, 2);
        $this->runStageAt(2, 4);
        $this->assertCount(0, $this->messagesTo(self::ADMIN_CHAT));

        // Vaqti kelmasdan (5-daqiqa) — hech narsa.
        $this->runStageAt(3, 5);
        $this->assertCount(0, $this->messagesTo(self::ADMIN_CHAT));

        $this->runStageAt(3, 7);
        $this->runStageAt(3, 8); // job qayta ishladi

        $this->assertCount(1, $this->messagesTo(self::ADMIN_CHAT));
    }

    public function test_running_the_same_stage_twice_does_not_repeat_messages(): void
    {
        $this->runStageAt(1, 2);
        $this->runStageAt(1, 2);
        $this->runStageAt(1, 3);

        $this->assertCount(1, $this->messagesTo(self::KITCHEN_CHAT));
        $this->assertCount(1, $this->messagesTo(self::OWNER_CHAT));
        Notification::assertSentToTimes($this->kitchen, NewKitchenOrderPushNotification::class, 1);
        Queue::assertPushed(EscalateUnacceptedOrder::class, 1);
    }

    public function test_stage_before_its_time_does_nothing(): void
    {
        $this->runStageAt(1, 1);

        $this->assertSame([], $this->sentMessages());
        $this->assertSame(0, $this->order->fresh()->escalation_stage);
    }

    public function test_telegram_failure_does_not_break_the_chain_or_throw(): void
    {
        $this->bot->willReceive(['error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], false);

        $this->runStageAt(1, 2);

        // Bitta xodim bloklagan — ikkinchisiga baribir ketdi, keyingi bosqich navbatda.
        $this->assertCount(1, $this->messagesTo(self::OWNER_CHAT));
        Queue::assertPushed(EscalateUnacceptedOrder::class, fn ($job) => $job->stage === 2);
    }

    public function test_schedule_queues_stage_one_two_minutes_after_the_order(): void
    {
        EscalateUnacceptedOrder::schedule($this->order);

        Queue::assertPushed(EscalateUnacceptedOrder::class, fn ($job) => $job->stage === 1
            && $job->orderId === $this->order->id
            && $job->delay->equalTo($this->placedAt->copy()->addMinutes(2)));
    }

    public function test_stage_timings_come_from_config(): void
    {
        config(['kitchen.escalation_minutes' => [1, 3, 5]]);

        EscalateUnacceptedOrder::schedule($this->order);

        Queue::assertPushed(EscalateUnacceptedOrder::class, fn ($job) => $job->delay->equalTo($this->placedAt->copy()->addMinutes(1)));
    }

    // --- Push bildirishnomasi kuchliroq ---

    public function test_push_payload_is_sticky_loud_and_grouped_per_order(): void
    {
        $notification = new NewKitchenOrderPushNotification('YT-100200', '2 ta taom', 4);
        $message = $notification->toWebPush($this->kitchen, $notification);
        $payload = $message->toArray();

        $this->assertSame('order-YT-100200', $payload['tag']);
        $this->assertTrue($payload['renotify']);
        $this->assertTrue($payload['requireInteraction']);
        $this->assertSame(NewKitchenOrderPushNotification::VIBRATE_PATTERN, $payload['vibrate']);
        $this->assertStringContainsString('4 daqiqadan beri', $payload['body']);
        $this->assertSame('high', $message->getOptions()['urgency']);
    }
}
