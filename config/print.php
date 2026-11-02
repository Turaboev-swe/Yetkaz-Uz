<?php

/**
 * Chek printeri (print agent) monitoringi.
 */
return [
    // Agent shuncha soniya jim bo'lsa (heartbeat kelmasa) «Printer agenti o'chiq» ogohlantirishi chiqadi.
    'agent_offline_after_seconds' => 120,

    // Buyurtma yaratilgandan shuncha soniya ichida printed_at to'lmasa «Chek chiqmadi» belgisi qo'yiladi
    // (faqat Reverb xatosiga tayanilmaydi — agent o'chiq yoki printer ishlamayotgan holatlar ham).
    'unprinted_after_seconds' => 120,

    // Agent `pending` endpoint'i faqat shu soatdan yangi buyurtmalarni qaytaradi; eskilari
    // chop etilmaydi, lekin panelda «Chek chiqmadi» bo'lib ko'rinib turadi.
    'pending_window_hours' => 3,
];
