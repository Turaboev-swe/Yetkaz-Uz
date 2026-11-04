<?php

/**
 * Yetkaz biznes sozlamalari.
 */
return [
    /*
    | Mahsulot qoldig'i ko'rsatilishi: qoldiq shu songa teng yoki kam bo'lganda Mini App'da
    | «Faqat N ta qoldi!» chiqadi. `null` — cheklangan qoldig'i bor har bir mahsulotda doim ko'rsatiladi.
    | Cheklanmagan (stock_quantity = NULL) mahsulotda hech qachon ko'rsatilmaydi.
    | .env: STOCK_DISPLAY_THRESHOLD=5 (yoki `null`).
    */
    'stock_display_threshold' => env('STOCK_DISPLAY_THRESHOLD', 5),
];
