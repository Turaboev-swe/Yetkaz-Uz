<?php

namespace Tests\Feature;

use App\Events\OrderPlaced;
use App\Listeners\NotifyKitchenStaffOfNewOrderPush;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Models\User;
use App\Notifications\NewKitchenOrderPushNotification;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Minishlink\WebPush\MessageSentReport;
use NotificationChannels\WebPush\ReportHandler;
use NotificationChannels\WebPush\WebPushMessage;
use Tests\TestCase;

/**
 * /kitchen Web Push: obuna saqlash/o'chirish, yangi buyurtmada obunachilarga
 * xabar, eskirgan obunani avtomatik tozalash (paket o'zi, ReportHandler).
 */
class KitchenPushNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Staff $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->restaurant = Restaurant::factory()->create();
        $this->owner = Staff::factory()->owner($this->restaurant)->create();
    }

    private function subscriptionPayload(string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123'): array
    {
        return [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => str_repeat('a', 20), 'auth' => str_repeat('b', 10)],
        ];
    }

    // --- Obuna saqlash/o'chirish -----------------------------------------

    public function test_subscribing_stores_the_push_subscription_for_the_staff(): void
    {
        $this->actingAs($this->owner, 'staff')
            ->postJson('/kitchen/push/subscribe', $this->subscriptionPayload())
            ->assertCreated()
            ->assertJsonPath('data.subscribed', true);

        $this->assertSame(1, $this->owner->pushSubscriptions()->count());
        $this->assertDatabaseHas('push_subscriptions', [
            'subscribable_type' => Staff::class,
            'subscribable_id' => $this->owner->id,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
        ]);
    }

    public function test_unsubscribing_removes_the_stored_subscription(): void
    {
        $payload = $this->subscriptionPayload();
        $this->actingAs($this->owner, 'staff')->postJson('/kitchen/push/subscribe', $payload);

        $this->actingAs($this->owner, 'staff')
            ->deleteJson('/kitchen/push/subscribe', ['endpoint' => $payload['endpoint']])
            ->assertOk()
            ->assertJsonPath('data.subscribed', false);

        $this->assertSame(0, $this->owner->pushSubscriptions()->count());
    }

    public function test_kitchen_staff_cannot_be_impersonated_by_another_staffs_subscription(): void
    {
        $other = Staff::factory()->owner(Restaurant::factory()->create())->create();
        $payload = $this->subscriptionPayload('https://fcm.googleapis.com/fcm/send/shared');

        $this->actingAs($this->owner, 'staff')->postJson('/kitchen/push/subscribe', $payload);
        // Xuddi shu endpoint boshqa xodimda qayta obuna bo'lsa — eskisi o'chib, yangisiga bog'lanadi.
        $this->actingAs($other, 'staff')->postJson('/kitchen/push/subscribe', $payload);

        $this->assertSame(0, $this->owner->fresh()->pushSubscriptions()->count());
        $this->assertSame(1, $other->fresh()->pushSubscriptions()->count());
    }

    // --- Payload tarkibi (logotip ikonkasi) -------------------------------

    public function test_push_payload_includes_the_logo_icon_and_badge(): void
    {
        $message = (new NewKitchenOrderPushNotification('YT-100200', '2 ta taom, 45 000 so‘m'))
            ->toWebPush($this->owner, new NewKitchenOrderPushNotification('YT-100200', '2 ta taom, 45 000 so‘m'));

        $payload = $message->toArray();

        $this->assertSame('/images/yetkaz-logo.png', $payload['icon']);
        $this->assertSame('/images/yetkaz-badge.png', $payload['badge']);
    }

    // --- Yangi buyurtmada push -------------------------------------------

    public function test_new_order_notifies_only_subscribed_kitchen_staff(): void
    {
        Notification::fake();

        $subscribed = Staff::factory()->kitchenStaff($this->restaurant)->create();
        $subscribed->updatePushSubscription('https://fcm.googleapis.com/fcm/send/x1', 'key', 'auth');

        $notSubscribed = Staff::factory()->kitchenStaff($this->restaurant)->create();

        $order = Order::factory()->for($this->restaurant)->for(User::factory())->create([
            'total' => 45_000_00,
            'items' => [['product_id' => 1, 'name' => 'Osh', 'price' => 45_000_00, 'qty' => 1]],
        ]);

        (new NotifyKitchenStaffOfNewOrderPush)->handle(new OrderPlaced($order->id, $order->restaurant_id));

        Notification::assertSentTo($subscribed, NewKitchenOrderPushNotification::class);
        Notification::assertNotSentTo($notSubscribed, NewKitchenOrderPushNotification::class);
        Notification::assertNotSentTo($this->owner, NewKitchenOrderPushNotification::class);
    }

    public function test_no_subscribers_means_no_notification_and_no_error(): void
    {
        Notification::fake();

        $order = Order::factory()->for($this->restaurant)->for(User::factory())->create();

        (new NotifyKitchenStaffOfNewOrderPush)->handle(new OrderPlaced($order->id, $order->restaurant_id));

        Notification::assertNothingSent();
    }

    public function test_other_restaurants_subscribed_staff_is_not_notified(): void
    {
        Notification::fake();

        $foreignRestaurant = Restaurant::factory()->create();
        $foreignStaff = Staff::factory()->kitchenStaff($foreignRestaurant)->create();
        $foreignStaff->updatePushSubscription('https://fcm.googleapis.com/fcm/send/foreign', 'key', 'auth');

        $order = Order::factory()->for($this->restaurant)->for(User::factory())->create();

        (new NotifyKitchenStaffOfNewOrderPush)->handle(new OrderPlaced($order->id, $order->restaurant_id));

        Notification::assertNotSentTo($foreignStaff, NewKitchenOrderPushNotification::class);
    }

    // --- Eskirgan obunani avtomatik tozalash ------------------------------

    /**
     * Bu — real WebPush server'ga so'rov yubormasdan sinash: paketning o'z
     * `ReportHandler`iga 410 (Gone) statusli soxta hisobot beramiz va
     * obuna o'chirilishini tasdiqlaymiz. Yuborish logikasi (WebPushChannel)
     * har doim shu handler'ga murojaat qiladi, shuning uchun bu real oqimni
     * to'g'ri aks ettiradi.
     */
    public function test_expired_subscription_is_deleted_automatically(): void
    {
        $subscription = $this->owner->updatePushSubscription('https://fcm.googleapis.com/fcm/send/dead', 'key', 'auth');

        $report = new MessageSentReport(
            new Psr7Request('POST', $subscription->endpoint),
            new Psr7Response(410),
            success: false,
        );

        app(ReportHandler::class)->handleReport($report, $subscription, new WebPushMessage);

        $this->assertDatabaseMissing('push_subscriptions', ['id' => $subscription->id]);
    }

    public function test_a_successful_report_does_not_delete_the_subscription(): void
    {
        $subscription = $this->owner->updatePushSubscription('https://fcm.googleapis.com/fcm/send/alive', 'key', 'auth');

        $report = new MessageSentReport(
            new Psr7Request('POST', $subscription->endpoint),
            new Psr7Response(201),
            success: true,
        );

        app(ReportHandler::class)->handleReport($report, $subscription, new WebPushMessage);

        $this->assertDatabaseHas('push_subscriptions', ['id' => $subscription->id]);
    }
}
