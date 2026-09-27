<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Events\OrderPlaced;
use App\Jobs\AlertAdminOfUnacceptedOrder;
use App\Jobs\RepeatKitchenPush;
use App\Listeners\NotifyKitchenStaffOfNewOrder;
use App\Models\District;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Models\User;
use App\Notifications\NewKitchenOrderPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use SergiX44\Nutgram\Nutgram;
use Tests\TestCase;

/**
 * Qabul qilinmagan buyurtma:
 * - push har 60s takrorlanadi (qabul/bekor qilinguncha, ≤30 daqiqa);
 * - restoran xodimlariga Telegram eslatmasi YO'Q;
 * - 7 daqiqada platforma adminiga bir martalik Telegram.
 *
 * Navbat Queue::fake — zanjirning keyingi halqasi olinib, vaqt oldinga
 * surilib, qo'lda ishga tushiriladi (haqiqiy navbat qanday qilsa shunday).
 */
class UnacceptedOrderAlertsTest extends TestCase
{
    use RefreshDatabase;

    private const KITCHEN_CHAT = 1001;

    private const OWNER_CHAT = 1002;

    private const ADMIN_CHAT = 9001;

    private Carbon $placedAt;

    private Staff $kitchen;

    private Order $order;

    private Nutgram $bot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->placedAt = Carbon::parse('2026-09-27 09:00:00');
        Carbon::setTestNow($this->placedAt);
        Queue::fake();
        Notification::fake();

        $restaurant = Restaurant::factory()->for(District::factory())->create([
            'name' => 'Istiqlol Food',
            'phone' => '+998901112233',
        ]);
        $this->kitchen = Staff::factory()->kitchenStaff($restaurant)->create(['telegram_chat_id' => self::KITCHEN_CHAT]);
        $this->kitchen->updatePushSubscription('https://fcm.googleapis.com/fcm/send/k1', 'key', 'auth');
        Staff::factory()->owner($restaurant)->create(['telegram_chat_id' => self::OWNER_CHAT]);
        Staff::factory()->platformAdmin()->create(['telegram_chat_id' => self::ADMIN_CHAT]);

        $this->order = Order::factory()
            ->for($restaurant)
            ->for(User::factory()->state(['phone' => '+998935556677']))
            ->create(['status' => OrderStatus::New, 'order_number' => 'YT-100200']);

        $this->bot = app(Nutgram::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Navbatdagi eng oxirgi RepeatKitchenPush halqasini vaqtida ishga tushiradi.
     * Halqa bo'lmasa — null (zanjir tugagan).
     */
    private function runNextPushLink(?int $afterSeconds = null): ?RepeatKitchenPush
    {
        $job = Queue::pushed(RepeatKitchenPush::class)->last();

        if ($job === null) {
            return null;
        }

        Carbon::setTestNow($afterSeconds === null
            ? Carbon::createFromTimestamp($job->dueAt)
            : $this->placedAt->copy()->addSeconds($afterSeconds));

        $this->forgetPushedLinks();
        $job->handle();

        return $job;
    }

    /** Queue::fake ro'yxatini tozalaydi — keyingi halqa aniq ko'rinsin. */
    private function forgetPushedLinks(): void
    {
        Queue::fake();
    }

    private function pushCount(): int
    {
        return Notification::sent($this->kitchen, NewKitchenOrderPushNotification::class)->count();
    }

    /** @return list<int> */
    private function telegramChats(): array
    {
        $chats = [];
        foreach ($this->bot->getRequestHistory() as $entry) {
            if (! str_ends_with((string) $entry['request']->getUri(), 'sendMessage')) {
                continue;
            }
            $json = json_decode((string) $entry['request']->getBody(), true) ?? [];
            $chats[] = (int) ($json['chat_id'] ?? 0);
        }

        return $chats;
    }

    private function runAdminAlert(int $minutesAfter, ?int $dueMinutes = 7): void
    {
        Carbon::setTestNow($this->placedAt->copy()->addMinutes($minutesAfter));
        $dueAt = $this->placedAt->copy()->addMinutes($dueMinutes)->getTimestamp();

        (new AlertAdminOfUnacceptedOrder($this->order->id, $dueAt))->handle($this->bot);
    }

    // --- Push takrori ---

    public function test_push_repeats_every_interval_until_accepted(): void
    {
        RepeatKitchenPush::start($this->order);

        foreach ([1, 2, 3] as $n) {
            $job = $this->runNextPushLink();
            $this->assertSame($this->placedAt->copy()->addSeconds(60 * $n)->getTimestamp(), $job->dueAt);
            $this->assertSame($n, $this->pushCount());
        }

        $this->order->update(['status' => OrderStatus::Accepted]);
        $this->runNextPushLink();

        $this->assertSame(3, $this->pushCount());
        Queue::assertNothingPushed(); // zanjir tugadi
    }

    public function test_reminder_push_says_how_long_the_order_has_been_waiting(): void
    {
        RepeatKitchenPush::start($this->order);
        $this->runNextPushLink();
        $this->runNextPushLink();

        Notification::assertSentTo($this->kitchen, NewKitchenOrderPushNotification::class, function ($n) {
            $payload = $n->toWebPush($this->kitchen, $n)->toArray();

            return str_contains($payload['body'], '2 daqiqadan beri')
                && $payload['tag'] === 'order-YT-100200'
                && $payload['renotify'] === true;
        });
    }

    public function test_push_stops_when_the_order_is_cancelled(): void
    {
        RepeatKitchenPush::start($this->order);
        $this->runNextPushLink();

        $this->order->update(['status' => OrderStatus::Cancelled]);
        $this->runNextPushLink();

        $this->assertSame(1, $this->pushCount());
        Queue::assertNothingPushed();
    }

    public function test_push_stops_after_the_maximum_duration(): void
    {
        RepeatKitchenPush::start($this->order);

        $links = 0;
        while ($this->runNextPushLink() !== null && $links < 100) {
            $links++;
        }

        // 30 daqiqa / 60s = 30 ta takror (1..30-daqiqa), keyin halqa qo'yilmaydi.
        $this->assertSame(30, $this->pushCount());
        $this->assertSame(30, $links);
        Queue::assertNothingPushed();
    }

    public function test_a_link_that_wakes_after_the_deadline_sends_nothing(): void
    {
        $late = new RepeatKitchenPush($this->order->id, $this->placedAt->copy()->addMinutes(31)->getTimestamp());
        Carbon::setTestNow($this->placedAt->copy()->addMinutes(31));

        $late->handle();

        $this->assertSame(0, $this->pushCount());
        Queue::assertNothingPushed();
    }

    public function test_parallel_chain_or_rerun_sends_only_one_push_per_interval(): void
    {
        $due = $this->placedAt->copy()->addMinute()->getTimestamp();
        Carbon::setTestNow($this->placedAt->copy()->addMinute());

        (new RepeatKitchenPush($this->order->id, $due))->handle();
        (new RepeatKitchenPush($this->order->id, $due))->handle(); // ikkinchi zanjir / qayta ishlash
        Carbon::setTestNow($this->placedAt->copy()->addSeconds(75));
        (new RepeatKitchenPush($this->order->id, $due))->handle();

        $this->assertSame(1, $this->pushCount());
        Queue::assertPushed(RepeatKitchenPush::class, 1); // faqat bitta davom etuvchi zanjir
    }

    public function test_link_running_before_its_time_does_nothing(): void
    {
        $due = $this->placedAt->copy()->addMinute()->getTimestamp();
        Carbon::setTestNow($this->placedAt->copy()->addSeconds(20));

        (new RepeatKitchenPush($this->order->id, $due))->handle();

        $this->assertSame(0, $this->pushCount());
        $this->assertNull($this->order->fresh()->last_push_at);
    }

    public function test_repeat_interval_and_duration_come_from_config(): void
    {
        config(['kitchen.push_repeat_seconds' => 120, 'kitchen.push_repeat_max_minutes' => 6]);
        RepeatKitchenPush::start($this->order);

        $links = 0;
        while ($this->runNextPushLink() !== null && $links < 100) {
            $links++;
        }

        $this->assertSame(3, $this->pushCount()); // 2, 4, 6-daqiqa
    }

    // --- Xodimlarga Telegram eslatmasi yo'q ---

    public function test_staff_never_get_telegram_reminders(): void
    {
        RepeatKitchenPush::start($this->order);
        for ($i = 0; $i < 10; $i++) {
            $this->runNextPushLink();
        }
        $this->runAdminAlert(7);

        $this->assertNotContains(self::KITCHEN_CHAT, $this->telegramChats());
        $this->assertNotContains(self::OWNER_CHAT, $this->telegramChats());
    }

    // --- Admin ogohlantirishi ---

    public function test_admin_is_alerted_once_at_seven_minutes_with_phones(): void
    {
        $this->runAdminAlert(5); // vaqti kelmagan
        $this->assertSame([], $this->telegramChats());

        $this->runAdminAlert(7);
        $this->runAdminAlert(8); // job qayta ishladi

        $this->assertSame([self::ADMIN_CHAT], $this->telegramChats());

        $body = json_decode((string) $this->bot->getRequestHistory()[0]['request']->getBody(), true);
        foreach (['Istiqlol Food', 'YT-100200', '7 daqiqa', '+998901112233', '+998935556677'] as $expected) {
            $this->assertStringContainsString($expected, $body['text']);
        }
    }

    public function test_admin_is_not_alerted_if_accepted_before_seven_minutes(): void
    {
        $this->order->update(['status' => OrderStatus::Accepted]);

        $this->runAdminAlert(7);

        $this->assertSame([], $this->telegramChats());
    }

    public function test_admin_alert_does_not_depend_on_the_push_chain(): void
    {
        // Push obunasi umuman yo'q — zanjir hech narsa yubormaydi, admin baribir oladi.
        $this->kitchen->pushSubscriptions()->delete();

        $this->runAdminAlert(7);

        $this->assertSame([self::ADMIN_CHAT], $this->telegramChats());
    }

    public function test_admins_sharing_a_chat_id_get_one_message(): void
    {
        Staff::factory()->platformAdmin()->create(['telegram_chat_id' => self::ADMIN_CHAT]);

        $this->runAdminAlert(7);

        $this->assertSame([self::ADMIN_CHAT], $this->telegramChats());
    }

    // --- Yangi buyurtma Telegram xabari: bir chat — bir xabar ---

    public function test_new_order_message_goes_once_per_chat_even_if_staff_share_a_chat_id(): void
    {
        Staff::factory()->kitchenStaff($this->order->restaurant_id)->create(['telegram_chat_id' => self::KITCHEN_CHAT]);

        (new NotifyKitchenStaffOfNewOrder)->handle(new OrderPlaced($this->order->id, $this->order->restaurant_id));

        $chats = $this->telegramChats();
        sort($chats);
        $this->assertSame([self::KITCHEN_CHAT, self::OWNER_CHAT], $chats);
    }
}
