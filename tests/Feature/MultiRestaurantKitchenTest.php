<?php

namespace Tests\Feature;

use App\Broadcasting\KitchenChannel;
use App\Enums\DeliveryType;
use App\Enums\OrderStatus;
use App\Events\OrderPlaced;
use App\Events\OrderStatusChanged;
use App\Jobs\RepeatKitchenPush;
use App\Listeners\NotifyKitchenStaffOfNewOrder;
use App\Listeners\NotifyKitchenStaffOfNewOrderPush;
use App\Models\District;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Models\User;
use App\Notifications\NewKitchenOrderPushNotification;
use App\Telegram\Support\KitchenOrderMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\UpdateType;
use Tests\TestCase;

/**
 * Bitta oshxona xodimi bir nechta restoranga biriktirilgan (restaurant_staff
 * pivot): masalan bir egasining "Sushi Xan" va "Vanilla" restoranlari bitta
 * /kitchen panelida. Uchinchi restoran ("Boshqa") — begona.
 */
class MultiRestaurantKitchenTest extends TestCase
{
    use RefreshDatabase;

    private const SHARED_CHAT = 710710;

    private Restaurant $sushi;

    private Restaurant $vanilla;

    private Restaurant $foreign;

    /** Sushi Xan (asosiy) + Vanilla. */
    private Staff $shared;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Event::fake([OrderStatusChanged::class]);
        Queue::fake();

        $this->sushi = Restaurant::factory()->for(District::factory())->create(['name' => 'Sushi Xan']);
        $this->vanilla = Restaurant::factory()->for(District::factory())->create(['name' => 'Vanilla']);
        $this->foreign = Restaurant::factory()->for(District::factory())->create(['name' => 'Boshqa']);

        $this->shared = Staff::factory()->kitchenStaff($this->sushi)->alsoAssignedTo($this->vanilla)
            ->withTelegramChatId(self::SHARED_CHAT)->create(['name' => 'Umumiy oshpaz']);
    }

    /** @param array<string, mixed> $attrs */
    private function order(Restaurant $restaurant, array $attrs = []): Order
    {
        return Order::factory()->for($restaurant)->for(User::factory())->create(array_replace([
            'status' => OrderStatus::New,
            'delivery_type' => DeliveryType::Delivery,
        ], $attrs));
    }

    private function click(int $chatId, string $data): Nutgram
    {
        $bot = app(Nutgram::class);

        $bot->hearUpdateType(UpdateType::CALLBACK_QUERY, [
            'from' => ['id' => $chatId, 'first_name' => 'X'],
            'data' => $data,
            'message' => [
                'message_id' => 50,
                'date' => 1703892479,
                'chat' => ['id' => $chatId, 'type' => 'private'],
            ],
        ])->reply();

        return $bot;
    }

    // --- Model -------------------------------------------------------------------

    public function test_primary_restaurant_is_always_in_the_pivot(): void
    {
        $this->assertSame([$this->sushi->id, $this->vanilla->id], $this->shared->restaurantIds());
        $this->assertTrue($this->shared->canManageRestaurant($this->sushi));
        $this->assertTrue($this->shared->canManageRestaurant($this->vanilla->id));
        $this->assertFalse($this->shared->canManageRestaurant($this->foreign));
    }

    public function test_changing_restaurant_id_moves_a_single_restaurant_staff(): void
    {
        $cook = Staff::factory()->kitchenStaff($this->sushi)->create();

        $cook->update(['restaurant_id' => $this->foreign->id]);

        $this->assertSame([$this->foreign->id], $cook->restaurantIds());
    }

    public function test_inactive_or_non_kitchen_roles_cannot_manage(): void
    {
        $inactive = Staff::factory()->kitchenStaff($this->sushi)->alsoAssignedTo($this->vanilla)->inactive()->create();
        $admin = Staff::factory()->platformAdmin()->create();

        $this->assertFalse($inactive->canManageRestaurant($this->vanilla));
        $this->assertFalse($admin->canManageRestaurant($this->sushi));
        $this->assertFalse($admin->canManageKitchen());
    }

    // --- /kitchen: ko'rish --------------------------------------------------------

    public function test_orders_come_from_all_assigned_restaurants_with_the_restaurant_name(): void
    {
        $a = $this->order($this->sushi);
        $b = $this->order($this->vanilla);
        $this->order($this->foreign);

        $data = $this->actingAs($this->shared, 'staff')
            ->getJson('/kitchen/orders')
            ->assertOk()
            ->json('data');

        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($data, 'id'));
        $names = collect($data)->pluck('restaurant.name', 'id');
        $this->assertSame('Sushi Xan', $names[$a->id]);
        $this->assertSame('Vanilla', $names[$b->id]);
    }

    public function test_page_lists_every_assigned_restaurant_primary_first(): void
    {
        $this->actingAs($this->shared, 'staff')
            ->get('/kitchen')
            ->assertOk()
            ->assertSee('<title>Oshxona — Sushi Xan · Vanilla</title>', false)
            ->assertSee('"name":"Sushi Xan"', false)
            ->assertSee('"name":"Vanilla"', false)
            ->assertDontSee('Boshqa');
    }

    // --- /kitchen: boshqarish -----------------------------------------------------

    public function test_shared_staff_advances_and_cancels_orders_of_both_restaurants(): void
    {
        $a = $this->order($this->sushi);
        $b = $this->order($this->vanilla, ['status' => OrderStatus::Accepted]);

        $this->actingAs($this->shared, 'staff')
            ->patchJson("/kitchen/orders/{$a->id}/advance")
            ->assertOk()
            ->assertJsonPath('data.restaurant.name', 'Sushi Xan');

        $this->actingAs($this->shared, 'staff')
            ->patchJson("/kitchen/orders/{$b->id}/cancel", ['reason' => 'Taom tugab qoldi'])
            ->assertOk();

        $this->assertSame('accepted', $a->fresh()->status->value);
        $this->assertSame('cancelled', $b->fresh()->status->value);
    }

    public function test_third_restaurant_order_cannot_be_managed(): void
    {
        $c = $this->order($this->foreign, ['status' => OrderStatus::Accepted]);

        $this->actingAs($this->shared, 'staff')->patchJson("/kitchen/orders/{$c->id}/advance")->assertForbidden();
        $this->actingAs($this->shared, 'staff')
            ->patchJson("/kitchen/orders/{$c->id}/cancel", ['reason' => 'x'])
            ->assertForbidden();

        $this->assertSame('accepted', $c->fresh()->status->value);
    }

    public function test_owner_of_two_restaurants_manages_the_second_one_too(): void
    {
        // RestaurantScope egasiga faqat asosiy restoranni ko'rsatadi — /kitchen scope'siz topadi.
        $owner = Staff::factory()->owner($this->sushi)->alsoAssignedTo($this->vanilla)->create();
        $b = $this->order($this->vanilla);
        $c = $this->order($this->foreign);

        $this->actingAs($owner, 'staff')->patchJson("/kitchen/orders/{$b->id}/advance")->assertOk();
        $this->actingAs($owner, 'staff')->patchJson("/kitchen/orders/{$c->id}/advance")->assertNotFound();

        $this->assertSame('accepted', $b->fresh()->status->value);
        $this->assertSame('new', $c->fresh()->status->value);
    }

    public function test_restaurant_panel_scope_is_unchanged_for_a_multi_restaurant_owner(): void
    {
        $owner = Staff::factory()->owner($this->sushi)->alsoAssignedTo($this->vanilla)->create();
        $a = $this->order($this->sushi);
        $this->order($this->vanilla);

        $this->actingAs($owner, 'staff');

        // /restaurant paneli (RestaurantScope) — faqat asosiy restoran, avvalgidek.
        $this->assertSame([$a->id], Order::query()->pluck('id')->all());
    }

    // --- Kuryer ro'yxati ------------------------------------------------------------

    public function test_courier_list_is_the_order_restaurants_staff_including_shared(): void
    {
        $vanillaCook = Staff::factory()->kitchenStaff($this->vanilla)->create(['name' => 'Vanilla oshpazi']);
        Staff::factory()->kitchenStaff($this->sushi)->create(['name' => 'Sushi oshpazi']);

        $names = $this->actingAs($this->shared, 'staff')
            ->getJson("/kitchen/couriers?restaurant_id={$this->vanilla->id}")
            ->assertOk()
            ->json('data.*.name');

        $this->assertEqualsCanonicalizing(['Umumiy oshpaz', 'Vanilla oshpazi'], $names);

        $this->actingAs($this->shared, 'staff')
            ->getJson("/kitchen/couriers?restaurant_id={$this->foreign->id}")
            ->assertForbidden();

        // Vanilla buyurtmasiga umumiy xodim va Vanilla xodimi — mumkin, Sushi xodimi — yo'q.
        $b = $this->order($this->vanilla, ['status' => OrderStatus::Preparing]);
        $this->actingAs($this->shared, 'staff')
            ->patchJson("/kitchen/orders/{$b->id}/advance", ['courier_type' => 'own_staff', 'courier_staff_id' => $vanillaCook->id])
            ->assertOk();
        $this->assertSame($vanillaCook->id, $b->fresh()->courier_staff_id);
    }

    public function test_courier_from_another_restaurant_is_rejected(): void
    {
        $sushiCook = Staff::factory()->kitchenStaff($this->sushi)->create();
        $b = $this->order($this->vanilla, ['status' => OrderStatus::Preparing]);

        $this->actingAs($this->shared, 'staff')
            ->patchJson("/kitchen/orders/{$b->id}/advance", ['courier_type' => 'own_staff', 'courier_staff_id' => $sushiCook->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['courier_staff_id']);

        $this->assertSame('preparing', $b->fresh()->status->value);
    }

    // --- Bir restoranli xodim — avvalgidek -----------------------------------------

    public function test_single_restaurant_staff_behaves_as_before(): void
    {
        $cook = Staff::factory()->kitchenStaff($this->sushi)->create(['name' => 'Yakka oshpaz']);
        $a = $this->order($this->sushi);
        $b = $this->order($this->vanilla);

        $ids = $this->actingAs($cook, 'staff')->getJson('/kitchen/orders')->assertOk()->json('data.*.id');
        $this->assertSame([$a->id], $ids);

        // restaurant_id'siz eski so'rov — asosiy restoran xodimlari.
        $names = $this->actingAs($cook, 'staff')->getJson('/kitchen/couriers')->assertOk()->json('data.*.name');
        $this->assertEqualsCanonicalizing(['Umumiy oshpaz', 'Yakka oshpaz'], $names);

        $this->actingAs($cook, 'staff')->patchJson("/kitchen/orders/{$b->id}/advance")->assertForbidden();
        $this->actingAs($cook, 'staff')->patchJson("/kitchen/orders/{$a->id}/advance")->assertOk();

        $channel = new KitchenChannel;
        $this->assertTrue($channel->join($cook, $this->sushi->id));
        $this->assertFalse($channel->join($cook, $this->vanilla->id));
    }

    // --- Reverb kanali -----------------------------------------------------------------

    public function test_channel_authorizes_each_assigned_restaurant_only(): void
    {
        $channel = new KitchenChannel;

        $this->assertTrue($channel->join($this->shared, $this->sushi->id));
        $this->assertTrue($channel->join($this->shared, $this->vanilla->id));
        $this->assertFalse($channel->join($this->shared, $this->foreign->id));
    }

    // --- Telegram callback'lari ------------------------------------------------------------

    public function test_telegram_advance_works_for_both_restaurants_and_not_for_the_third(): void
    {
        $a = $this->order($this->sushi);
        $b = $this->order($this->vanilla);
        $c = $this->order($this->foreign);

        $this->click(self::SHARED_CHAT, "kadv:{$a->id}:new");
        $this->click(self::SHARED_CHAT, "kadv:{$b->id}:new");
        $bot = $this->click(self::SHARED_CHAT, "kadv:{$c->id}:new");

        $this->assertSame('accepted', $a->fresh()->status->value);
        $this->assertSame('accepted', $b->fresh()->status->value);
        $this->assertSame('new', $c->fresh()->status->value);
        $bot->assertCalled('editMessageText', 0);
    }

    public function test_telegram_picks_the_staff_record_of_the_orders_restaurant_not_the_first_one(): void
    {
        // Bir odam — ikki alohida hisob, bir xil chat. Birinchi (kichik id) — Sushi Xan'niki.
        Staff::factory()->kitchenStaff($this->sushi)->withTelegramChatId(720720)->create();
        $vanillaAccount = Staff::factory()->kitchenStaff($this->vanilla)->withTelegramChatId(720720)->create();
        $b = $this->order($this->vanilla);

        $this->click(720720, "kadv:{$b->id}:new");

        $this->assertSame('accepted', $b->fresh()->status->value);
        $this->assertSame("staff:{$vanillaAccount->id}", $b->statusHistory()->latest('id')->value('changed_by'));
    }

    public function test_telegram_cancel_and_courier_callbacks_respect_the_assignment(): void
    {
        $b = $this->order($this->vanilla, ['status' => OrderStatus::Accepted]);
        $c = $this->order($this->foreign, ['status' => OrderStatus::Accepted]);

        $this->click(self::SHARED_CHAT, "kcreason:{$b->id}:busy");
        $this->click(self::SHARED_CHAT, "kcreason:{$c->id}:busy");

        $this->assertSame('cancelled', $b->fresh()->status->value);
        $this->assertSame('accepted', $c->fresh()->status->value);

        $vanillaCook = Staff::factory()->kitchenStaff($this->vanilla)->create();
        $d = $this->order($this->vanilla, ['status' => OrderStatus::Preparing]);
        $e = $this->order($this->foreign, ['status' => OrderStatus::Preparing]);

        $this->click(self::SHARED_CHAT, "kcourierpick:{$d->id}:preparing:{$vanillaCook->id}");
        $this->click(self::SHARED_CHAT, "kcourierpick:{$e->id}:preparing:0");

        $this->assertSame('on_the_way', $d->fresh()->status->value);
        $this->assertSame($vanillaCook->id, $d->fresh()->courier_staff_id);
        $this->assertSame('preparing', $e->fresh()->status->value);
    }

    // --- Bildirishnomalar ------------------------------------------------------------------

    public function test_shared_staff_gets_exactly_one_telegram_message_per_order(): void
    {
        Staff::factory()->kitchenStaff($this->vanilla)->withTelegramChatId(730730)->create();
        // O'sha odamning Vanilla uchun alohida hisobi ham bor — baribir bitta xabar.
        Staff::factory()->kitchenStaff($this->vanilla)->withTelegramChatId(self::SHARED_CHAT)->create();
        Staff::factory()->kitchenStaff($this->foreign)->withTelegramChatId(740740)->create();

        $b = $this->order($this->vanilla);
        (new NotifyKitchenStaffOfNewOrder)->handle(new OrderPlaced($b->id, $b->restaurant_id));

        // shared (710710) + Vanilla oshpazi (730730) = 2; begona restoran (740740) — yo'q.
        app(Nutgram::class)->assertCalled('sendMessage', 2);
    }

    public function test_telegram_message_header_names_the_restaurant(): void
    {
        $text = app(KitchenOrderMessage::class)->text($this->order($this->vanilla)->load('user'));

        $this->assertStringContainsString('🏪 <b>Vanilla</b>', strtok($text, "\n"));
    }

    public function test_push_goes_once_to_shared_staff_with_the_restaurant_in_the_title(): void
    {
        Notification::fake();
        $this->shared->updatePushSubscription('https://fcm.googleapis.com/fcm/send/shared', 'key', 'auth');
        $foreignCook = Staff::factory()->kitchenStaff($this->foreign)->create();
        $foreignCook->updatePushSubscription('https://fcm.googleapis.com/fcm/send/foreign', 'key', 'auth');

        $b = $this->order($this->vanilla);
        (new NotifyKitchenStaffOfNewOrderPush)->handle(new OrderPlaced($b->id, $b->restaurant_id));

        Notification::assertSentToTimes($this->shared, NewKitchenOrderPushNotification::class, 1);
        Notification::assertNotSentTo($foreignCook, NewKitchenOrderPushNotification::class);
        Notification::assertSentTo($this->shared, NewKitchenOrderPushNotification::class, function ($n, $channels, $notifiable) {
            return $n->toWebPush($notifiable, $n)->toArray()['title'] === '🔔 Yangi buyurtma! — Vanilla';
        });
    }

    public function test_repeat_push_reaches_shared_staff_via_the_pivot(): void
    {
        Notification::fake();
        $this->shared->updatePushSubscription('https://fcm.googleapis.com/fcm/send/shared', 'key', 'auth');
        $b = $this->order($this->vanilla, ['created_at' => now()->subMinutes(2)]);

        (new RepeatKitchenPush($b->id, now()->getTimestamp()))->handle();

        Notification::assertSentToTimes($this->shared, NewKitchenOrderPushNotification::class, 1);
        Notification::assertSentTo($this->shared, NewKitchenOrderPushNotification::class, function ($n, $channels, $notifiable) {
            return $n->toWebPush($notifiable, $n)->toArray()['title'] === '⏰ Buyurtma qabul qilinmadi! — Vanilla';
        });
    }
}
