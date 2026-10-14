<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Events\OrderContactPhoneChanged;
use App\Events\OrderMessageCreated;
use App\Jobs\NotifyRestaurantOfCustomerMessage;
use App\Jobs\SendOrderChatMessageToCustomer;
use App\Models\District;
use App\Models\Order;
use App\Models\OrderMessage;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Models\User;
use App\Notifications\NewOrderChatMessagePushNotification;
use App\Services\Chat\OrderChatException;
use App\Services\Chat\OrderChatService;
use App\Services\Chat\PendingOrderChatStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Properties\UpdateType;
use Tests\TestCase;

/** Buyurtma chati: qoidalar, ruxsatlar, yetkazish job'lari, bot oqimi, "raqam ishlamayapti". */
class OrderChatTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOMER_TG = 880011;

    private Restaurant $restaurant;

    private Staff $owner;

    private User $customer;

    private OrderChatService $chat;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        $this->restaurant = Restaurant::factory()->for(District::factory())->create(['name' => 'Osh Markazi']);
        $this->owner = Staff::factory()->owner($this->restaurant)->withTelegramChatId(555001)->create(['name' => 'Maxfiy Xodim']);
        $this->customer = User::factory()->create([
            'telegram_id' => self::CUSTOMER_TG,
            'profile_completed' => true,
            'language' => 'uz',
            'phone' => '+998901112233',
            'username' => 'secret_nick',
            'full_name' => 'Mijoz Ismi',
        ]);
        $this->chat = app(OrderChatService::class);
    }

    private function order(array $attrs = []): Order
    {
        return Order::factory()->for($this->restaurant)->for($this->customer)->create(array_replace([
            'status' => OrderStatus::Preparing,
            'contact_phone' => '+998901112233',
        ], $attrs));
    }

    private function reason(callable $fn): ?string
    {
        try {
            $fn();
        } catch (OrderChatException $e) {
            return $e->reason;
        }

        return null;
    }

    // --- Ochiq/yopiq qoidasi ----------------------------------------------

    public function test_chat_is_open_while_order_is_active(): void
    {
        foreach ([OrderStatus::New, OrderStatus::Accepted, OrderStatus::Preparing, OrderStatus::OnTheWay] as $status) {
            $this->assertTrue($this->chat->isOpen($this->order(['status' => $status])), $status->value);
        }
    }

    public function test_chat_closes_immediately_when_the_order_is_delivered_or_cancelled(): void
    {
        $factory = fn () => Order::factory()->for($this->restaurant)->for($this->customer);

        // Hozirgina yetkazilgan/bekor qilingan — 1 soatlik oyna yo'q, darhol yopiq.
        $this->assertFalse($this->chat->isOpen($factory()->delivered(now())->create()));
        $this->assertFalse($this->chat->isOpen($factory()->delivered(now()->subMinute())->create()));
        $this->assertFalse($this->chat->isOpen($factory()->cancelled(now())->create()));
        $this->assertFalse($this->chat->isOpen($factory()->cancelled(now()->subMinutes(10))->create()));

        // Faol buyurtma ochiq qoladi, statusi o'zgargach yopiladi.
        $order = $this->order(['status' => OrderStatus::OnTheWay]);
        $this->assertTrue($this->chat->isOpen($order));
        $order->update(['status' => OrderStatus::Delivered, 'delivered_at' => now()]);
        $this->assertFalse($this->chat->isOpen($order->fresh()));
    }

    public function test_messages_are_rejected_when_chat_is_closed(): void
    {
        Queue::fake();
        $closed = Order::factory()->for($this->restaurant)->for($this->customer)->delivered(now())->create();

        $this->assertSame('closed', $this->reason(fn () => $this->chat->sendFromCustomer($closed, $this->customer, 'Salom')));
        $this->assertSame('closed', $this->reason(fn () => $this->chat->sendFromRestaurant($closed, $this->owner, 'Salom')));
        $this->assertSame(0, OrderMessage::count());
    }

    // --- Tekshiruvlar -------------------------------------------------------

    public function test_empty_too_long_and_control_characters(): void
    {
        Queue::fake();
        $order = $this->order();

        $this->assertSame('empty', $this->reason(fn () => $this->chat->sendFromCustomer($order, $this->customer, "  \n\t ")));
        $this->assertSame('too_long', $this->reason(fn () => $this->chat->sendFromCustomer($order, $this->customer, str_repeat('a', 501))));

        $ok = $this->chat->sendFromCustomer($order, $this->customer, str_repeat('я', 500));
        $this->assertSame(500, mb_strlen($ok->message->body));

        $clean = $this->chat->sendFromCustomer($order, $this->customer, "Sa\u{0000}lom\u{202E}!\r\n\n\n\nOxiri");
        $this->assertSame("Salom!\n\nOxiri", $clean->message->body);
    }

    public function test_markup_in_body_is_stored_verbatim_as_text(): void
    {
        Queue::fake();
        $order = $this->order();

        $result = $this->chat->sendFromCustomer($order, $this->customer, '<script>alert(1)</script> <b>x</b>');

        // Saqlanadi o'zgarishsiz; chiqarishda: React escape qiladi, Telegram'ga parse_mode'siz boradi.
        $this->assertSame('<script>alert(1)</script> <b>x</b>', $result->message->body);
    }

    public function test_rate_limit_is_ten_per_minute_per_order_and_side(): void
    {
        Queue::fake();
        $order = $this->order();
        $other = $this->order();

        for ($i = 1; $i <= 10; $i++) {
            $this->chat->sendFromCustomer($order, $this->customer, "xabar {$i}");
        }

        $this->assertSame('throttled', $this->reason(fn () => $this->chat->sendFromCustomer($order, $this->customer, 'o‘n birinchi')));
        // Restoran tomoni va boshqa buyurtma alohida hisoblanadi.
        $this->assertNull($this->reason(fn () => $this->chat->sendFromRestaurant($order, $this->owner, 'javob')));
        $this->assertNull($this->reason(fn () => $this->chat->sendFromCustomer($other, $this->customer, 'boshqa buyurtma')));
    }

    // --- Ruxsatlar -----------------------------------------------------------

    public function test_customer_cannot_write_to_someone_elses_order(): void
    {
        Queue::fake();
        $stranger = User::factory()->create(['telegram_id' => 990022]);

        $this->assertSame('forbidden', $this->reason(fn () => $this->chat->sendFromCustomer($this->order(), $stranger, 'Salom')));
        $this->assertSame(0, OrderMessage::count());
    }

    public function test_staff_of_another_restaurant_cannot_write_or_read(): void
    {
        Queue::fake();
        $order = $this->order();
        $foreignRestaurant = Restaurant::factory()->for(District::factory())->create();
        $foreignOwner = Staff::factory()->owner($foreignRestaurant)->create();
        $foreignKitchen = Staff::factory()->kitchenStaff($foreignRestaurant)->create();
        $admin = Staff::factory()->platformAdmin()->create();

        $this->assertSame('forbidden', $this->reason(fn () => $this->chat->sendFromRestaurant($order, $foreignOwner, 'Salom')));
        $this->assertSame('forbidden', $this->reason(fn () => $this->chat->requestPhone($order, $foreignKitchen)));

        // HTTP: egasiga 404, oshxona xodimiga 403, admin'ga 403.
        $this->actingAs($foreignOwner, 'staff')->getJson("/kitchen/orders/{$order->id}/messages")->assertNotFound();
        $this->actingAs($foreignOwner, 'staff')->postJson("/kitchen/orders/{$order->id}/messages", ['body' => 'x'])->assertNotFound();
        $this->actingAs($foreignKitchen, 'staff')->getJson("/kitchen/orders/{$order->id}/messages")->assertForbidden();
        $this->actingAs($foreignKitchen, 'staff')->postJson("/kitchen/orders/{$order->id}/phone-unreachable")->assertForbidden();
        $this->actingAs($admin, 'staff')->getJson("/kitchen/orders/{$order->id}/messages")->assertForbidden();

        $this->assertSame(0, OrderMessage::count());
    }

    // --- Panel API -----------------------------------------------------------

    public function test_kitchen_api_lists_messages_marks_read_and_hides_private_data(): void
    {
        Queue::fake();
        $order = $this->order();
        $this->chat->sendFromCustomer($order, $this->customer, 'Salom, qachon keladi?');
        $this->chat->sendFromRestaurant($order, $this->owner, 'Yarim soatda');

        $unread = fn () => collect($this->actingAs($this->owner, 'staff')->getJson('/kitchen/orders')->assertOk()->json('data'))
            ->firstWhere('id', $order->id)['chat']['unread'];
        $this->assertSame(1, $unread());

        $res = $this->actingAs($this->owner, 'staff')->getJson("/kitchen/orders/{$order->id}/messages")->assertOk();

        $res->assertJsonPath('data.0.sender', 'customer')
            ->assertJsonPath('data.0.sender_label', 'Mijoz')
            ->assertJsonPath('data.1.sender', 'restaurant')
            ->assertJsonPath('data.1.sender_label', 'Osh Markazi');

        $json = json_encode($res->json());
        foreach (['secret_nick', 'Maxfiy Xodim', 'sender_id', 'Mijoz Ismi'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }

        $this->assertSame(0, $unread(), 'ochilgach o‘qilgan deb belgilanadi');
    }

    public function test_kitchen_api_sends_text_and_quick_replies_and_validates(): void
    {
        Queue::fake();
        $order = $this->order();

        $this->actingAs($this->owner, 'staff')->postJson("/kitchen/orders/{$order->id}/messages", ['body' => 'Tez orada'])
            ->assertCreated()->assertJsonPath('data.body', 'Tez orada');

        $this->actingAs($this->owner, 'staff')->postJson("/kitchen/orders/{$order->id}/messages", ['quick' => 'clarify_address'])
            ->assertCreated()->assertJsonPath('data.body', 'Manzilni aniqlashtiring');

        $this->actingAs($this->owner, 'staff')->postJson("/kitchen/orders/{$order->id}/messages", ['quick' => 'nope'])->assertStatus(422);
        $this->actingAs($this->owner, 'staff')->postJson("/kitchen/orders/{$order->id}/messages", ['body' => str_repeat('x', 501)])
            ->assertStatus(422)->assertJsonPath('reason', 'too_long');
        $this->actingAs($this->owner, 'staff')->postJson("/kitchen/orders/{$order->id}/messages", ['body' => '   '])->assertStatus(422);

        Queue::assertPushed(SendOrderChatMessageToCustomer::class, 2);
    }

    public function test_kitchen_api_rate_limit_returns_429(): void
    {
        Queue::fake();
        $order = $this->order();

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($this->owner, 'staff')->postJson("/kitchen/orders/{$order->id}/messages", ['body' => "x{$i}"])->assertCreated();
        }

        $this->actingAs($this->owner, 'staff')->postJson("/kitchen/orders/{$order->id}/messages", ['body' => 'yana'])->assertStatus(429);
    }

    public function test_quick_reply_text_is_in_customer_language(): void
    {
        Queue::fake();
        $this->customer->update(['language' => 'ru']);
        $order = $this->order();

        $message = $this->chat->sendFromRestaurant($order, $this->owner, null, 'accepted');

        $this->assertSame('Заказ принят', $message->body);
    }

    public function test_message_events_and_jobs_are_dispatched(): void
    {
        Queue::fake();
        Event::fake([OrderMessageCreated::class]);
        $order = $this->order();

        $this->chat->sendFromCustomer($order, $this->customer, 'Salom');
        $this->chat->sendFromRestaurant($order, $this->owner, 'Salom!');

        Event::assertDispatched(OrderMessageCreated::class, 2);
        Queue::assertPushed(NotifyRestaurantOfCustomerMessage::class, 1);
        Queue::assertPushed(SendOrderChatMessageToCustomer::class, 1);
    }

    // --- Mijozga yetkazish (job) ----------------------------------------------

    public function test_restaurant_reply_is_delivered_with_restaurant_name_and_reply_button_only(): void
    {
        Queue::fake();
        $order = $this->order();
        $message = $this->chat->sendFromRestaurant($order, $this->owner, 'Yarim soatda yetib boradi');

        $bot = app(Nutgram::class);
        (new SendOrderChatMessageToCustomer($message->id))->handle($bot);

        $bot->assertCalled('sendMessage');
        $bot->assertReplyText("Osh Markazi · Buyurtma #{$order->order_number}:\nYarim soatda yetib boradi");
        $bot->assertRaw(fn ($request) => str_contains((string) $request->getBody(), "chat:{$order->id}")
            && ! str_contains((string) $request->getBody(), 'Maxfiy Xodim'));
    }

    public function test_delivery_uses_customer_language_without_leaking_locale(): void
    {
        Queue::fake();
        $this->customer->update(['language' => 'ru']);
        $order = $this->order();
        $message = $this->chat->sendFromRestaurant($order, $this->owner, 'Скоро будет');
        $before = app()->getLocale();

        $bot = app(Nutgram::class);
        (new SendOrderChatMessageToCustomer($message->id))->handle($bot);

        $bot->assertReplyText("Osh Markazi · Заказ #{$order->order_number}:\nСкоро будет");
        $this->assertSame($before, app()->getLocale());
    }

    public function test_blocked_bot_is_swallowed_when_delivering_to_customer(): void
    {
        Queue::fake();
        $message = $this->chat->sendFromRestaurant($this->order(), $this->owner, 'Salom');

        $bot = Mockery::mock(Nutgram::class);
        $bot->shouldReceive('sendMessage')->once()->andThrow(new TelegramException('Forbidden: bot was blocked by the user', 403));

        (new SendOrderChatMessageToCustomer($message->id))->handle($bot);

        $this->assertTrue(true); // xato tashqariga chiqmadi
    }

    public function test_customer_messages_are_never_sent_back_to_the_customer(): void
    {
        Queue::fake();
        $result = $this->chat->sendFromCustomer($this->order(), $this->customer, 'Salom');

        $bot = Mockery::mock(Nutgram::class);
        $bot->shouldNotReceive('sendMessage');

        (new SendOrderChatMessageToCustomer($result->message->id))->handle($bot);
        $this->assertTrue(true);
    }

    // --- Restoran egasiga bildirishnoma ------------------------------------------

    public function test_owner_gets_short_bot_notification_and_push_is_sent_once_per_window(): void
    {
        Queue::fake();
        Notification::fake();
        $this->owner->updatePushSubscription('https://fcm.googleapis.com/fcm/send/chat1', 'key', 'auth');
        $order = $this->order();
        $first = $this->chat->sendFromCustomer($order, $this->customer, 'Salom, manzilni o‘zgartirsam bo‘ladimi?');
        $second = $this->chat->sendFromCustomer($order, $this->customer, 'Javob bering');

        $bot = app(Nutgram::class);
        (new NotifyRestaurantOfCustomerMessage($first->message->id))->handle($bot);

        $bot->assertCalled('sendMessage');
        $bot->assertReplyText("💬 Mijozdan xabar — Osh Markazi\nBuyurtma #{$order->order_number}\n\n«Salom, manzilni o‘zgartirsam bo‘ladimi?»");
        Notification::assertSentTo($this->owner, NewOrderChatMessagePushNotification::class);

        // Debounce: ikkinchi xabar uchun qayta bildirishnoma yo'q.
        $bot2 = Mockery::mock(Nutgram::class);
        $bot2->shouldNotReceive('sendMessage');
        (new NotifyRestaurantOfCustomerMessage($second->message->id))->handle($bot2);
        Notification::assertSentToTimes($this->owner, NewOrderChatMessagePushNotification::class, 1);
    }

    // --- Bot oqimi (mijoz) -----------------------------------------------------------

    private function pressChatButton(int $orderId, int $fromId = self::CUSTOMER_TG): Nutgram
    {
        $bot = app(Nutgram::class);
        $bot->hearUpdateType(UpdateType::CALLBACK_QUERY, [
            'from' => ['id' => $fromId, 'first_name' => 'X'],
            'data' => "chat:{$orderId}",
            'message' => ['message_id' => 77, 'date' => 1703892479, 'chat' => ['id' => $fromId, 'type' => 'private']],
        ])->reply();

        return $bot;
    }

    private function customerSays(string $text): Nutgram
    {
        $bot = app(Nutgram::class);
        $bot->hearMessage(['from' => ['id' => self::CUSTOMER_TG, 'first_name' => 'X'], 'text' => $text])->reply();

        return $bot;
    }

    public function test_button_then_text_saves_message_and_notifies_restaurant(): void
    {
        Queue::fake();
        $order = $this->order();

        $this->pressChatButton($order->id)->assertCalled('sendMessage');
        $this->assertSame($order->id, app(PendingOrderChatStore::class)->pending(self::CUSTOMER_TG));

        $bot = $this->customerSays('Iltimos, achchiqsiz qiling');

        $bot->assertReplyText(__('messages.order_chat.sent'));
        $message = OrderMessage::first();
        $this->assertSame('customer', $message->sender_type);
        $this->assertSame('Iltimos, achchiqsiz qiling', $message->body);
        $this->assertSame($order->id, $message->order_id);
        Queue::assertPushed(NotifyRestaurantOfCustomerMessage::class);
    }

    public function test_button_of_someone_elses_order_is_ignored(): void
    {
        Queue::fake();
        $order = $this->order();
        User::factory()->create(['telegram_id' => 990033, 'profile_completed' => true]);

        $this->pressChatButton($order->id, 990033);

        $this->assertNull(app(PendingOrderChatStore::class)->pending(990033));
    }

    public function test_button_on_closed_chat_does_not_start_a_conversation(): void
    {
        Queue::fake();
        $closed = Order::factory()->for($this->restaurant)->for($this->customer)->delivered(now())->create();

        $bot = $this->pressChatButton($closed->id);

        $this->assertNull(app(PendingOrderChatStore::class)->pending(self::CUSTOMER_TG));
        $bot->assertRaw(fn ($request) => str_contains((string) $request->getBody(), 'show_alert')
            && str_contains((string) $request->getBody(), 'Buyurtma yakunlangan. Muammo yoki taklif bo‘lsa'));
    }

    public function test_writing_to_a_finished_order_points_to_feedback_section_in_both_languages(): void
    {
        Queue::fake();
        $order = $this->order();
        app(PendingOrderChatStore::class)->remember(self::CUSTOMER_TG, $order->id);
        $order->update(['status' => OrderStatus::Delivered, 'delivered_at' => now()]); // suhbat paytida yetkazildi

        $bot = $this->customerSays('Hali shu yerdaman');

        $bot->assertReplyText('Buyurtma yakunlangan. Muammo yoki taklif bo‘lsa, «💬 Taklif va shikoyat» bo‘limi orqali yozing.');
        $this->assertNull(app(PendingOrderChatStore::class)->pending(self::CUSTOMER_TG), 'suhbat tugaydi');
        $this->assertSame(0, OrderMessage::count());

        // Ruscha
        $this->customer->update(['language' => 'ru']);
        app(PendingOrderChatStore::class)->remember(self::CUSTOMER_TG, $order->id);
        $this->customerSays('Я ещё здесь')->assertReplyText('Заказ завершён. Если есть проблема или предложение, напишите через раздел «💬 Отзывы и жалобы».');
    }

    public function test_kitchen_api_rejects_messages_for_a_finished_order(): void
    {
        Queue::fake();
        $order = $this->order(['status' => OrderStatus::Delivered, 'delivered_at' => now()]);

        $this->actingAs($this->owner, 'staff')->postJson("/kitchen/orders/{$order->id}/messages", ['body' => 'Salom'])
            ->assertStatus(422)->assertJsonPath('reason', 'closed');
        $this->actingAs($this->owner, 'staff')->postJson("/kitchen/orders/{$order->id}/phone-unreachable")->assertStatus(422);
        $this->assertSame(0, OrderMessage::count());
    }

    public function test_menu_button_ends_the_chat_conversation(): void
    {
        Queue::fake();
        $order = $this->order();
        app(PendingOrderChatStore::class)->remember(self::CUSTOMER_TG, $order->id);

        $this->customerSays(__('messages.main_menu.settings'));

        $this->assertNull(app(PendingOrderChatStore::class)->pending(self::CUSTOMER_TG));
        $this->assertSame(0, OrderMessage::count());
    }

    public function test_text_without_active_chat_is_not_saved_as_message(): void
    {
        Queue::fake();
        $this->order();

        $this->customerSays('Oddiy matn');

        $this->assertSame(0, OrderMessage::count());
    }

    public function test_status_message_to_customer_carries_the_chat_button(): void
    {
        Queue::fake();
        $order = $this->order(['status' => OrderStatus::Accepted]);

        $bot = app(Nutgram::class);
        (new \App\Jobs\NotifyCustomerOfStatusChange($order->id))->handle($bot);

        $bot->assertRaw(fn ($request) => str_contains((string) $request->getBody(), "chat:{$order->id}"));
    }

    // --- "Raqam ishlamayapti" -------------------------------------------------------------

    public function test_phone_unreachable_sends_message_and_opens_customer_chat(): void
    {
        Queue::fake();
        $order = $this->order();

        $this->actingAs($this->owner, 'staff')->postJson("/kitchen/orders/{$order->id}/phone-unreachable")
            ->assertCreated()
            ->assertJsonPath('data.body', 'Raqamingizga ulanib bo‘lmadi, iltimos, to‘g‘ri raqamni yozing.');

        Queue::assertPushed(SendOrderChatMessageToCustomer::class);
        $this->assertTrue(app(PendingOrderChatStore::class)->isAwaitingPhone($order->id));
        $this->assertSame($order->id, app(PendingOrderChatStore::class)->pending(self::CUSTOMER_TG));

        $card = collect($this->actingAs($this->owner, 'staff')->getJson('/kitchen/orders')->json('data'))->firstWhere('id', $order->id);
        $this->assertTrue($card['chat']['awaiting_phone']);
    }

    public function test_valid_phone_reply_updates_order_and_user_contact_phone_and_notifies_kitchen(): void
    {
        Queue::fake();
        Event::fake([OrderMessageCreated::class, OrderContactPhoneChanged::class]);
        $order = $this->order();
        $this->chat->requestPhone($order, $this->owner);

        $bot = $this->customerSays('8 (93) 555-66-77');

        $bot->assertReplyText(__('messages.order_chat.phone_updated', ['phone' => '+998935556677']));
        $this->assertSame('+998935556677', $order->fresh()->contact_phone);
        $this->assertSame('+998935556677', $this->customer->fresh()->contact_phone);
        $this->assertSame('+998901112233', $this->customer->fresh()->phone, 'Telegram raqami o‘zgarmaydi');
        $this->assertFalse(app(PendingOrderChatStore::class)->isAwaitingPhone($order->id));
        Event::assertDispatched(OrderContactPhoneChanged::class, fn ($e) => $e->orderId === $order->id);
    }

    public function test_invalid_phone_reply_keeps_waiting_and_leaves_numbers_untouched(): void
    {
        Queue::fake();
        Event::fake([OrderMessageCreated::class, OrderContactPhoneChanged::class]);
        $order = $this->order();
        $this->chat->requestPhone($order, $this->owner);

        $bot = $this->customerSays('mening raqamim 12345');

        $bot->assertReplyText(__('messages.order_chat.sent').' '.__('messages.contact_phone.invalid'));
        $this->assertSame('+998901112233', $order->fresh()->contact_phone);
        $this->assertTrue(app(PendingOrderChatStore::class)->isAwaitingPhone($order->id));
        Event::assertNotDispatched(OrderContactPhoneChanged::class);

        // Keyin to'g'ri raqam kelsa baribir yangilanadi.
        $this->customerSays('901234567');
        $this->assertSame('+998901234567', $order->fresh()->contact_phone);
    }

    public function test_phone_in_normal_chat_does_not_change_contact_phone(): void
    {
        Queue::fake();
        $order = $this->order();

        $this->chat->sendFromCustomer($order, $this->customer, '935556677');

        $this->assertSame('+998901112233', $order->fresh()->contact_phone, 'faqat so‘ralgandagina yangilanadi');
    }

    public function test_contact_phone_changed_event_payload_has_both_flags(): void
    {
        $order = $this->order(['contact_phone' => '+998935556677']);

        $payload = (new OrderContactPhoneChanged($order->id, $this->restaurant->id))->broadcastWith();

        $this->assertSame('+998935556677', $payload['contact_phone']);
        $this->assertTrue($payload['phones_differ']);
    }
}
