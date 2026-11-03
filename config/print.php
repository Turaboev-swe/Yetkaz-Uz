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

    // Chek qog'ozi: kenglik (mm) -> qatordagi belgilar soni. Restoran sozlamasi `receipt_paper_mm`.
    'paper_columns' => [58 => 32, 80 => 42],
    'default_paper_mm' => 80,

    // Qatordagi belgilar soni variantlari (restoran sozlamasi `receipt_columns`, bo'sh bo'lsa paper_columns): 58 mm — 32,
    // 80 mm — 42 (xavfsiz standart) yoki 48 (80 mm printer Font A'da 576 nuqta = 48 belgi, masalan Xprinter XP-80T).
    'columns_allowed' => [32, 42, 48],

    // ESC t n — printer kodlash jadvali (restoran sozlamasi `receipt_codepage`). 16 = WPC1252 (Xprinter/Epson-mos).
    // Chekka faqat ASCII yoziladi, shuning uchun bu qiymat odatda natijaga ta'sir qilmaydi; noto'g'ri qiymat
    // ASCII'ni buzadigan jadvalni (masalan yapon) tanlasa — `print-agent:test-receipt --table` bilan tekshiring.
    'default_codepage' => 16,

    // Brauzer cheki (pos_type = browser): oshxona panelidan Windows printeriga, HTML/CSS maketda.
    'browser' => [
        // public/ ga nisbatan logo yo'li. Fayl almashtirilsa (yoki shu yerga yangi yo'l berilsa) chekka o'zi tushadi.
        'logo' => env('BROWSER_RECEIPT_LOGO', 'images/yetkaz-logo.png'),
        'logo_width_mm' => 40,

        // Chek tagidagi QR-kod ochadigan bot (t.me/<username>). Serverda SVG qilib yaratiladi, tashqi servis yo'q.
        'bot_username' => env('BROWSER_RECEIPT_BOT', 'Yetkaz_uzbot'),
        'qr_mm' => 30,

        // Qog'oz kengligi (mm) -> haqiqiy bosiladigan kenglik (printer chetlarida bo'sh joy qoladi).
        'printable_mm' => [58 => 48, 80 => 72],
    ],

    // Agent `pending` endpoint'i faqat shu soatdan yangi buyurtmalarni qaytaradi; eskilari
    // chop etilmaydi, lekin panelda «Chek chiqmadi» bo'lib ko'rinib turadi.
    'pending_window_hours' => 3,
];
