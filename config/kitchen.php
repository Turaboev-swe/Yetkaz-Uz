<?php

return [

    /*
    | Qabul qilinmagan buyurtma eslatmalari (EscalateUnacceptedOrder).
    | Buyurtma yaratilgandan necha daqiqa o'tib har bosqich ishlaydi:
    |   1 — push + Telegram eslatma barcha xodimlarga
    |   2 — push + Telegram, restoran egasiga alohida urg'u bilan
    |   3 — platforma adminiga Telegram (restoran/mijoz telefoni bilan)
    | .env: KITCHEN_ESCALATION_MINUTES="2,4,7"
    */
    'escalation_minutes' => array_map(
        'intval',
        explode(',', (string) env('KITCHEN_ESCALATION_MINUTES', '2,4,7')),
    ),

];
