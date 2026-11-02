<?php

/**
 * Restoranlar obunasi (/admin → Obunalar). Pullar tiyinda.
 */
return [
    // Shu sanadan oldingi oylar uchun hisob-kitob yaratilmaydi (Asia/Tashkent oyi).
    'start_date' => env('BILLING_START_DATE', '2026-11-01'),

    // Hisob-kitob to'lov muddati: oy tugagandan keyin shuncha kun.
    'due_days' => 10,

    // Yangi tarifning standart qiymatlari (form bo'sh qoldirilsa).
    'default_rate_bp' => 500,            // 5%
    'default_monthly_fee' => 10_000_000, // 100 000 so'm
];
