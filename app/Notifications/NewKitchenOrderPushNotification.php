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
 *
 * `$waitingMinutes` berilsa — takroriy eslatma (RepeatKitchenPush): buyurtma
 * N daqiqadan beri qabul qilinmagan.
 *
 * Bir buyurtmaning barcha push'lari bitta `tag` da (buyurtma raqami) —
 * eslatma avvalgisini almashtiradi (ekranda 5 ta bir xil yig'ilmaydi),
 * `renotify` esa baribir qayta ovoz/vibratsiya beradi.
 */
class NewKitchenOrderPushNotification extends Notification
{
    /** Kuchli, uzun vibratsiya (ms): 3 ta uzun zarba. */
    public const VIBRATE_PATTERN = [500, 200, 500, 200, 800];

    /** Eskirgan buyurtma push'i keyin kelishidan foyda yo'q — 10 daqiqa. */
    private const TTL_SECONDS = 600;

    public function __construct(
        private readonly string $orderNumber,
        private readonly string $summary,
        private readonly ?int $waitingMinutes = null,
        // Sarlavhada — bir nechta restoranli oshxona xodimi qaysi restoran ekanini darhol ko'rsin.
        private readonly ?string $restaurantName = null,
    ) {}

    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        $isReminder = $this->waitingMinutes !== null;
        $title = $isReminder ? '⏰ Buyurtma qabul qilinmadi!' : '🔔 Yangi buyurtma!';

        return (new WebPushMessage)
            ->title(filled($this->restaurantName) ? "{$title} — {$this->restaurantName}" : $title)
            ->body($isReminder
                ? "№{$this->orderNumber} — {$this->waitingMinutes} daqiqadan beri kutmoqda. {$this->summary}"
                : "№{$this->orderNumber} — {$this->summary}")
            ->icon('/images/yetkaz-logo.png')
            // Android status panelidagi kichik belgi — monoxrom, shaffof fon
            // (generatsiya: public/images/yetkaz-logo.png dagi "Y" belgisi,
            // aylana fonisiz, 96x96).
            ->badge('/images/yetkaz-badge.png')
            ->tag("order-{$this->orderNumber}")
            ->renotify()
            ->requireInteraction()
            ->vibrate(self::VIBRATE_PATTERN)
            ->data(['url' => '/kitchen'])
            // urgency=high — Android Doze/uyqu rejimida ham darhol yetkaziladi.
            ->options(['TTL' => self::TTL_SECONDS, 'urgency' => 'high']);
    }
}
