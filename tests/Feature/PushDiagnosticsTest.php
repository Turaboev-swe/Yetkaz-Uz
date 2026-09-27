<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\Staff;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use Mockery;
use NotificationChannels\WebPush\Events\NotificationFailed;
use NotificationChannels\WebPush\Events\NotificationSent;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;
use Tests\TestCase;

/**
 * Push diagnostikasi: har obuna natijasi WARNING log'ga yoziladi va
 * `push:test` har obuna uchun status/sabab/vaqtni ko'rsatadi.
 */
class PushDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private const FCM = 'https://fcm.googleapis.com/fcm/send/abc123';

    private Staff $staff;

    private PushSubscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = Staff::factory()->kitchenStaff(Restaurant::factory()->create())->create();
        $this->subscription = $this->staff->updatePushSubscription(self::FCM, 'key', 'auth');
    }

    private function report(?int $status, string $reason = 'OK', string $endpoint = self::FCM): MessageSentReport
    {
        return new MessageSentReport(
            new Request('POST', $endpoint),
            $status === null ? null : new Response($status),
            $status !== null && $status < 300,
            $reason,
        );
    }

    public function test_rejected_push_with_403_is_logged_as_warning_with_vapid_hint(): void
    {
        Log::spy();

        event(new NotificationFailed($this->report(403, 'Forbidden'), $this->subscription, new WebPushMessage));

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'YUBORILMADI')
            && $ctx['staff_id'] === $this->staff->id
            && $ctx['subscription_id'] === $this->subscription->id
            && $ctx['endpoint_host'] === 'fcm.googleapis.com'
            && $ctx['status'] === 403
            && $ctx['hint'] === 'VAPID mos kelmaydi — qayta obuna kerak');
    }

    public function test_timeout_without_response_is_logged_as_network_failure(): void
    {
        Log::spy();

        event(new NotificationFailed($this->report(null, 'cURL error 28: Operation timed out'), $this->subscription, new WebPushMessage));

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $msg, array $ctx) => ! array_key_exists('status', $ctx)
            && str_contains($ctx['reason'], 'timed out')
            && str_contains($ctx['hint'], 'timeout'));
    }

    public function test_successful_push_is_also_logged_at_warning_level(): void
    {
        Log::spy();

        event(new NotificationSent($this->report(201), $this->subscription, new WebPushMessage));

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $msg, array $ctx) => $msg === '[push] yuborildi'
            && $ctx['status'] === 201
            && ! array_key_exists('hint', $ctx));
    }

    public function test_push_http_client_has_short_timeouts(): void
    {
        $this->assertSame(5.0, config('webpush.client_options.connect_timeout'));
        $this->assertSame(10.0, config('webpush.client_options.timeout'));
    }

    // --- php artisan push:test ---

    private function fakeWebPush(MessageSentReport ...$reports): void
    {
        $webPush = Mockery::mock(WebPush::class);
        $webPush->shouldReceive('sendOneNotification')->andReturn(...$reports);

        $this->app->when(WebPushChannel::class)->needs(WebPush::class)->give(fn () => $webPush);
    }

    public function test_push_test_command_reports_each_subscription(): void
    {
        $second = $this->staff->updatePushSubscription('https://updates.push.services.mozilla.com/wpush/v2/x', 'key2', 'auth2');
        $this->fakeWebPush(
            $this->report(403, 'Forbidden'),
            $this->report(201, 'OK', 'https://updates.push.services.mozilla.com/wpush/v2/x'),
        );

        $this->artisan('push:test', ['staff_id' => $this->staff->id])
            ->expectsOutputToContain('2 ta obuna')
            // Har jadval qatori bitta kutishga mos keladi: 1-qator (fcm) — VAPID izohi, 2-qator — mozilla.
            ->expectsOutputToContain('VAPID mos kelmaydi — qayta obuna kerak')
            ->expectsOutputToContain('updates.push.services.mozilla.com')
            ->assertFailed(); // bittasi yiqildi

        $this->assertNotNull(PushSubscription::find($second->id));
    }

    public function test_push_test_command_succeeds_when_all_subscriptions_accept(): void
    {
        $this->fakeWebPush($this->report(201));

        $this->artisan('push:test', ['staff_id' => $this->staff->id])
            ->expectsOutputToContain('✅ OK')
            ->assertSuccessful();
    }

    public function test_push_test_command_removes_an_expired_subscription(): void
    {
        $this->fakeWebPush($this->report(410, 'Gone'));

        $this->artisan('push:test', ['staff_id' => $this->staff->id])
            ->expectsOutputToContain('obuna eskirgan')
            ->assertFailed();

        $this->assertNull(PushSubscription::find($this->subscription->id));
    }

    public function test_push_test_command_explains_missing_subscriptions(): void
    {
        $other = Staff::factory()->kitchenStaff(Restaurant::factory()->create())->create();

        $this->artisan('push:test', ['staff_id' => $other->id])
            ->expectsOutputToContain('Obuna yo\'q')
            ->assertFailed();
    }
}
