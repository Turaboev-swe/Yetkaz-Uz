<?php

return [

    /*
    | Mini App initData imzosi qancha vaqt yaroqli (soniya). auth_date shundan
    | eski bo'lsa so'rov rad etiladi.
    */
    'init_data_ttl' => (int) env('TELEGRAM_INIT_DATA_TTL', 86400),

    /*
    | Mini App URL — bot "Ochish" tugmasi shu manzilni ochadi.
    */
    'mini_app_url' => env('TELEGRAM_MINI_APP_URL'),

    /*
    | Webhook secret (PROD-2 / PROD-6). `telegram:webhook:set` uni `setWebhook`
    | ning `secret_token` iga beradi; Telegram har update so'rovida
    | `X-Telegram-Bot-Api-Secret-Token` sarlavhasida qaytaradi. Bir xil qiymat
    | webhook URL yo'lida ham (`/telegram/webhook/{token}`).
    | Bo'sh bo'lsa — webhook endpoint har doim 404 (hozirgi holat: bot polling).
    */
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),

    /*
    | Faqat lokal dev: brauzerda (Telegramsiz) Mini App'ni sinash uchun
    | imzolangan initData. `php artisan telegram:test-init-data --curl` bilan
    | oling. Faqat APP_ENV=local da blade'ga uzatiladi.
    */
    'dev_init_data' => env('TELEGRAM_DEV_INIT_DATA'),

];
