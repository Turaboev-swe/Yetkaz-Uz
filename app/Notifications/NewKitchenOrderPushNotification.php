<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * /kitchen uchun brauzer/OS bildirishnomasi — planshet qulflangan yoki
 * sahifa yopiq bo'lsa ham yetadi (Reverb ovoz signalidan farqli, u faqat
 * sahifa ochiq turganda ishlaydi). Ikkalasi ham OrderPlaced'ga QO'SHIMCHA
 * — biri ikkinchisini almashtirmaydi.
 */
class NewKitchenOrderPushNotification extends Notification
{
    public function __construct(
        private readonly string $orderNumber,
        private readonly string $summary,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('🔔 Yangi buyurtma!')
            ->body("№{$this->orderNumber} — {$this->summary}")
            ->icon('/images/yetkaz-logo.png')
            // Android status panelidagi kichik belgi — monoxrom, shaffof fon
            // (generatsiya: public/images/yetkaz-logo.png dagi "Y" belgisi,
            // aylana fonisiz, 96x96).
            ->badge('/images/yetkaz-badge.png')
            ->tag('kitchen-new-order')
            ->requireInteraction()
            ->data(['url' => '/kitchen']);
    }
}
