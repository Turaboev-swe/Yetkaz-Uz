<?php

/**
 * Restoranlar obunasi (/admin → Obunalar). Pullar tiyinda.
 */
return [
    // Shu sanadan oldingi oylar uchun hisob-kitob yaratilmaydi (Asia/Tashkent oyi).
    'start_date' => env('BILLING_START_DATE', '2026-11-01'),

    // Percent (oy oxirida, savdo bo'yicha): to'lov muddati — oy tugagandan keyin shuncha kun.
    'due_days' => 10,

    // Fixed (oldindan): to'lov muddati — shu oyning shu kuni...
    'fixed_due_day' => 5,

    // ...lekin hisob kech yaratilgan bo'lsa (oy o'rtasida boshlangan, kechiktirilgan) restoranga kamida
    // shuncha kun beriladi: muddat = max(oyning fixed_due_day-kuni, yaratilgan kun + (shuncha − 1)).
    // Yaratilgan kun birinchi kun hisoblanadi, shuning uchun 1-kungi oddiy generatsiyada muddat 5-sana.
    'fixed_late_grace_days' => 5,

    // Yangi tarifning standart qiymatlari (form bo'sh qoldirilsa).
    'default_rate_bp' => 500,            // 5%
    'default_monthly_fee' => 10_000_000, // 100 000 so'm
];
