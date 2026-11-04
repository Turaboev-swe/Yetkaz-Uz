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

        // Reverse xatosi (tarmoq, 4xx/5xx) shuncha soniya keshlanadi; muvaffaqiyatli javob 1 kun (AddressGeocoder::reverse).
        'error_cache_seconds' => (int) env('NOMINATIM_ERROR_CACHE_SECONDS', 60),
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

    /*
    |--------------------------------------------------------------------------
    | Olib ketish (pickup) uchun qidiruv radiusi
    |--------------------------------------------------------------------------
    | Yetkazib berishda har restoranning o'z `delivery_radius_km`si ishlatiladi.
    | Olib ketishda esa mijoz o'zi boradi — restoran radiusi cheklov emas,
    | shuning uchun kengroq, platforma darajasidagi FIKS radius ishlatiladi
    | (RestaurantFinder::deliveringTo). Restoranlar baribir masofa bo'yicha
    | tartiblanadi.
    */
    'pickup_radius_km' => (float) env('PICKUP_RADIUS_KM', 50),

];
