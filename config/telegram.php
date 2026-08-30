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

];
