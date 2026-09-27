<?php

namespace App\Listeners;

use App\Support\WebPushReportSummary;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\MessageSentReport;
use NotificationChannels\WebPush\Events\NotificationFailed;
use NotificationChannels\WebPush\Events\NotificationSent;
use NotificationChannels\WebPush\PushSubscription;

/**
 * Har bir Web Push obunasi natijasini WARNING darajasida yozadi.
 *
 * Nima uchun: webpush paketi rad etish (403 VAPID, 410 eskirgan, timeout)
 * bo'lsa exception tashlamaydi — faqat NotificationFailed hodisasi chiqadi.
 * Unga quloq solinmasa job DONE bo'ladi va log bo'sh qoladi (2026-09-27:
 * Istiqlol Food xodimlariga push kelmagan, izi yo'q). Production'da
 * LOG_LEVEL=warning — muvaffaqiyat ham ko'rinishi uchun shu daraja.
 *
 * Sinxron (ShouldQueue emas): hodisa push job'ining ichida chiqadi.
 */
class LogWebPushReport
{
    public function handleSent(NotificationSent $event): void
    {
        Log::warning('[push] yuborildi', $this->context($event->report, $event->subscription));
    }

    public function handleFailed(NotificationFailed $event): void
    {
        Log::warning('[push] YUBORILMADI', $this->context($event->report, $event->subscription));
    }

    /** @return array<string, mixed> */
    private function context(MessageSentReport $report, PushSubscription $subscription): array
    {
        $summary = WebPushReportSummary::from($report);

        return array_filter([
            'staff_id' => $subscription->subscribable_id,
            'subscription_id' => $subscription->id,
            'endpoint_host' => $summary['host'],
            'status' => $summary['status'],
            'reason' => $summary['reason'],
            'hint' => $summary['hint'],
        ], fn ($v) => $v !== null);
    }
}
