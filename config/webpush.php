<?php

use NotificationChannels\WebPush\PushSubscription;

return [

    /**
     * These are the keys for authentication (VAPID).
     * These keys must be safely stored and should not change.
     */
    'vapid' => [
        'subject' => env('VAPID_SUBJECT'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'pem_file' => env('VAPID_PEM_FILE'),
    ],

    /**
     * This is model that will be used to for push subscriptions.
     */
    'model' => PushSubscription::class,

    /**
     * This is the name of the table that will be created by the migration and
     * used by the PushSubscription model shipped with this package.
     */
    'table_name' => env('WEBPUSH_DB_TABLE', 'push_subscriptions'),

    /**
     * This is the database connection that will be used by the migration and
     * the PushSubscription model shipped with this package.
     */
    'database_connection' => env('WEBPUSH_DB_CONNECTION', env('DB_CONNECTION', 'mysql')),

    /**
     * The HTTP client options used to deliver push notifications.
     */
    //
    // Paket standarti: faqat 'timeout' => 30, connect_timeout yo'q, so'rovlar
    // ketma-ket. Push xizmati (FCM) javob bermasa har obuna 30 soniya band
    // qiladi va natija jim "rejected" bo'ladi (2026-09-27: 30 soniyalik job).
    // Ulanish 5s, butun so'rov 10s — sog'lom push xizmati <1s javob beradi.
    'client_options' => [
        'connect_timeout' => (float) env('WEBPUSH_CONNECT_TIMEOUT', 5),
        'timeout' => (float) env('WEBPUSH_TIMEOUT', 10),
    ],

    /**
     * The automatic padding in bytes used by Minishlink\WebPush.
     * Set to false to support Firefox Android with v1 endpoint.
     */
    'automatic_padding' => env('WEBPUSH_AUTOMATIC_PADDING', true),

];
