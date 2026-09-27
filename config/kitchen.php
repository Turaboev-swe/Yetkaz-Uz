<?php

return [

    /*
    | Qabul qilinmagan buyurtma — push takrorlanadi (RepeatKitchenPush).
    | Restoran xodimlariga Telegram xabari faqat BIR MARTA (yangi buyurtmada);
    | eslatma faqat push orqali: har N soniyada, buyurtma yaratilgandan
    | ko'pi bilan M daqiqa. Qabul/bekor qilinsa darhol to'xtaydi.
    */
    'push_repeat_seconds' => max(15, (int) env('KITCHEN_PUSH_REPEAT_SECONDS', 60)),

    'push_repeat_max_minutes' => (int) env('KITCHEN_PUSH_REPEAT_MAX_MINUTES', 30),

    /*
    | Platforma adminiga bir martalik Telegram ogohlantirish
    | (AlertAdminOfUnacceptedOrder) — buyurtma shuncha daqiqa qabul
    | qilinmasa. Push zanjiriga bog'liq emas.
    */
    'admin_alert_minutes' => (int) env('KITCHEN_ADMIN_ALERT_MINUTES', 7),

];
