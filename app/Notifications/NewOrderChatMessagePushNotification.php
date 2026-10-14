<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * /kitchen uchun Web Push: mijoz buyurtma chatiga yozdi. NewKitchenOrderPushNotification
 * bilan bir xil naqsh, lekin boshqa `tag` (chat-…) — yangi buyurtma push'ini almashtirmaydi.
 */
class NewOrderChatMessagePushNotification extends Notification
{
    private const TTL_SECONDS = 600;

    public function __construct(
        private readonly string $orderNumber,
        private readonly string $excerpt,
        private readonly ?string $restaurantName = null,
    ) {}

    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        $title = '💬 Mijozdan xabar';

        return (new WebPushMessage)
            ->title(filled($this->restaurantName) ? "{$title} — {$this->restaurantName}" : $title)
            ->body("№{$this->orderNumber}: {$this->excerpt}")
            ->icon('/images/yetkaz-logo.png')
            ->badge('/images/yetkaz-badge.png')
            ->tag("chat-{$this->orderNumber}")
            ->renotify()
            ->vibrate(NewKitchenOrderPushNotification::VIBRATE_PATTERN)
            ->data(['url' => '/kitchen'])
            ->options(['TTL' => self::TTL_SECONDS, 'urgency' => 'high']);
    }
}
