<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Nominatim (OpenStreetMap) geokodlash
    |--------------------------------------------------------------------------
    | `geo:fetch-coordinates` buyrug'i ishlatadi. Nominatim qoidasi bo'yicha
    | User-Agent majburiy (loyiha nomi + aloqa manzili). Soniyada 1 so'rov.
    */
    'nominatim' => [
        'base_url' => env('NOMINATIM_URL', 'https://nominatim.openstreetmap.org'),
        'contact' => env('NOMINATIM_CONTACT', 'https://github.com/Turaboev-swe/Yetkaz-Uz'),
        'user_agent' => env('APP_NAME', 'Yetkaz').'/1.0 (+'.env('NOMINATIM_CONTACT', 'https://github.com/Turaboev-swe/Yetkaz-Uz').')',
    ],

    /*
    |--------------------------------------------------------------------------
    | Viloyat chegaralari (taxminiy bounding box)
    |--------------------------------------------------------------------------
    | `district`.center_lat/lng shu quti ichida bo'lishi kerak — DistrictBoundsTest
    | tekshiradi. Yangi viloyat qo'shilganda bu yerga ham qo'shiladi, aks holda
    | test o'sha viloyatni "chegara aniqlanmagan" deb tushiradi.
    |
    | region_code => ['lat' => [min, max], 'lng' => [min, max]]
    */
    'region_bounds' => [
        // Andijon viloyati — Farg'ona vodiysi sharqi
        'AN' => ['lat' => [40.35, 41.10], 'lng' => [71.60, 73.20]],
    ],

];
