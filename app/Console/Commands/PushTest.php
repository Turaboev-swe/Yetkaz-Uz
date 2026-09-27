<?php

namespace App\Console\Commands;

use App\Models\Staff;
use App\Notifications\NewKitchenOrderPushNotification;
use App\Support\WebPushReportSummary;
use Illuminate\Console\Command;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Xodimning har bir push obunasiga SINXRON sinov push yuboradi va natijani
 * (HTTP status, sabab, vaqt) ekranga chiqaradi — navbat/log'siz, darhol.
 *
 *   php artisan push:test 26
 *
 * Xuddi shu HTTP klient va VAPID sozlamalari ishlatiladi (WebPushChannel
 * uchun konteynerdagi WebPush) — natija haqiqiy buyurtma push'i bilan bir xil.
 */
class PushTest extends Command
{
    protected $signature = 'push:test {staff_id : Xodim (staff) ID}';

    protected $description = 'Xodimning barcha push obunalariga sinov push yuborib, har birining natijasini ko\'rsatish';

    public function handle(): int
    {
        $staff = Staff::withoutGlobalScopes()->find($this->argument('staff_id'));

        if ($staff === null) {
            $this->error('Xodim topilmadi.');

            return self::FAILURE;
        }

        $subscriptions = $staff->pushSubscriptions()->get();

        $this->info("{$staff->name} (#{$staff->id}, {$staff->role->value}) — {$subscriptions->count()} ta obuna");

        if ($subscriptions->isEmpty()) {
            $this->warn('Obuna yo\'q — xodim /kitchen da bildirishnomalarga ruxsat bermagan.');

            return self::FAILURE;
        }

        $this->line('VAPID public key: '.substr((string) config('webpush.vapid.public_key'), 0, 16).'…');

        $webPush = $this->webPush();
        $notification = new NewKitchenOrderPushNotification('TEST', 'Sinov push — e\'tibor bermang');
        $message = $notification->toWebPush($staff, $notification);
        $payload = json_encode($message->toArray(), JSON_THROW_ON_ERROR);

        $rows = [];
        $failed = 0;

        foreach ($subscriptions as $subscription) {
            $started = microtime(true);

            $report = $webPush->sendOneNotification(new Subscription(
                $subscription->endpoint,
                $subscription->public_key,
                $subscription->auth_token,
                $subscription->content_encoding ?? ContentEncoding::aes128gcm,
            ), $payload, $message->getOptions());

            $ms = (int) round((microtime(true) - $started) * 1000);
            $summary = WebPushReportSummary::from($report);

            if ($report->isSubscriptionExpired()) {
                $subscription->delete(); // oddiy oqimdagi ReportHandler bilan bir xil
            }

            $failed += $summary['success'] ? 0 : 1;

            $rows[] = [
                $subscription->id,
                $summary['host'],
                $summary['status'] ?? '—',
                $summary['success'] ? '✅ OK' : '❌ XATO',
                $summary['hint'] ?? ($summary['success'] ? '' : $summary['reason']),
                "{$ms} ms",
            ];
        }

        $this->table(['Obuna', 'Push xizmati', 'HTTP', 'Natija', 'Sabab', 'Vaqt'], $rows);

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** WebPushChannel'ga beriladigan WebPush (VAPID + client_options bilan sozlangan). */
    private function webPush(): WebPush
    {
        $channel = app(WebPushChannel::class);

        return (fn (): WebPush => $this->webPush)->call($channel);
    }
}
