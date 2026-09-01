<?php

/*
 * O'zbekcha interfeys matnlari. Standart til — shu.
 * Kod ichida qattiq yozilmaydi: __('messages.key').
 *
 * DIQQAT: yangi qatorli matnlar DOIM qo'sh tirnoqda ("..."), aks holda \n
 * matn bo'lib chiqadi (bir tirnoqda \n harfma-harf yoziladi).
 */

return [
    // Umumiy
    'welcome' => "Yetkaz'ga xush kelibsiz!",
    'welcome_back' => 'Xush kelibsiz, :name!',
    'menu' => 'Menyu',
    'cart' => 'Savat',
    'back' => 'Orqaga',
    'cancel' => 'Bekor qilish',
    'unknown_command' => 'Tushunmadim. Quyidagi menyudan foydalaning.',

    // Ro'yxatdan o'tish
    'registration' => [
        'ask_phone' => "Boshlash uchun telefon raqamingiz kerak.\n\nPastdagi «📱 Raqamni yuborish» tugmasini bosing.",
        'ask_phone_button' => '📱 Raqamni yuborish',
        'phone_must_use_button' => "Iltimos, raqamni qo'lda yozmang — «📱 Raqamni yuborish» tugmasini bosing.",
        'phone_must_be_own' => "Iltimos, o'zingizning raqamingizni yuboring.",
        'ask_name' => 'Ismingizni kiriting:',
        'name_too_short' => "Ism juda qisqa. To'liq ismingizni yozing:",
        'ask_location' => "Endi yetkazib berish manzilingizni yuboring.\n\nPastdagi «📍 Lokatsiyani yuborish» tugmasini bosing yoki xarita orqali joyni tanlang.",
        'ask_location_button' => '📍 Lokatsiyani yuborish',
        'location_must_use_button' => 'Iltimos, «📍 Lokatsiyani yuborish» tugmasi orqali joylashuvingizni yuboring.',
        'done' => "Rahmat! Ro'yxatdan o'tish tugadi ✅\nEndi buyurtma berishingiz mumkin.",
        'home_label' => 'Uy',
    ],

    // Asosiy menyu (Reply Keyboard)
    'main_menu' => [
        'title' => 'Asosiy menyu',
        'order' => '🍿 Buyurtma berish',
        'order_intro' => "Boshlaymiz 🍿\n\nAjoyib buyurtma berish uchun quyidagi tugmani bosing!",
        'order_button' => 'Buyurtma berish',
        'new_address' => '📍 Yangi manzil',
        'restaurants' => '🏪 Restoranlar',
        'restaurants_intro' => 'Restoranlar ro‘yxati 🏪',
        'restaurants_button' => 'Ochish',
        'addresses' => '📌 Manzillarim',
        'feedback' => '💬 Taklif va shikoyat',
        'settings' => '⚙️ Sozlamalar',
    ],

    // Sozlamalar
    'settings' => [
        'title' => 'Sozlamalar',
        'language' => 'Til',
        'language_changed' => 'Til o‘zbekchaga o‘zgartirildi.',
        'choose_language' => 'Tilni tanlang:',
        'lang_uz' => '🇺🇿 O‘zbekcha',
        'lang_ru' => '🇷🇺 Русский',
    ],

    // Manzillar
    'addresses' => [
        'title' => 'Sizning manzillaringiz:',
        'empty' => "Sizda hali saqlangan manzil yo‘q.\n\n«📍 Yangi manzil» tugmasi orqali qo‘shing.",
        'hint' => 'Yangi manzil qo‘shish uchun «📍 Yangi manzil» tugmasini bosing.',
        'default_marker' => '  ✅ asosiy',
        'label' => 'Manzil :n',
        'added' => "✅ Yangi manzil qo‘shildi va asosiy qilib belgilandi:\n:address",
    ],

    // Taklif va shikoyat — hali ishlamaydi
    'feedback' => [
        'not_ready' => 'Bu bo‘lim hali ishlamaydi. Taklif va shikoyatlar tez orada shu yerda qabul qilinadi.',
    ],

    'mini_app_unavailable' => 'Mini App hozircha sozlanmagan. Iltimos, birozdan so‘ng urinib ko‘ring.',

    // Buyurtma statusi o'zgarganda mijozga (oshxona paneli)
    'order_notify' => [
        'accepted' => '✅ Buyurtmangiz (:n) qabul qilindi. Tez orada tayyorlashga kirishamiz.',
        'preparing' => '👨‍🍳 Buyurtmangiz (:n) tayyorlanmoqda.',
        'on_the_way' => '🛵 Buyurtmangiz (:n) yo‘lga chiqdi.',
        'delivered' => '🎉 Buyurtmangiz (:n) yetkazildi. Yoqimli ishtaha!',
        'picked_up' => '🎉 Buyurtmangiz (:n) topshirildi. Yoqimli ishtaha!',
        'cancelled' => '❌ Buyurtmangiz (:n) bekor qilindi.',
    ],

    // Savat / restoran
    'cart_kept' => 'Savatingiz saqlanadi.',
    'min_order_not_met' => "Minimal buyurtma summasi: :amount so'm.",
    'restaurant_closed' => 'Restoran hozir yopiq.',
    'out_of_radius' => 'Afsuski, bu manzilga yetkazib bera olmaymiz.',
    'cart_item_unavailable' => "Savatdagi ba'zi taomlar endi mavjud emas. Savatni yangilang.",

    // Enum yorliqlari
    'delivery_type' => [
        'delivery' => 'Yetkazib berish',
        'pickup' => 'Olib ketish',
    ],
    'order_status' => [
        'new' => 'Qabul qilindi',
        'accepted' => 'Tasdiqlandi',
        'preparing' => 'Tayyorlanmoqda',
        'on_the_way' => "Yo'lga chiqdi",
        'delivered' => 'Yetkazildi',
        'cancelled' => 'Bekor qilindi',
    ],
    'payment_method' => [
        'cash' => 'Naqd',
        'payme' => 'Payme',
        'click' => 'Click',
    ],
    'payment_status' => [
        'pending' => 'Kutilmoqda',
        'paid' => "To'landi",
        'failed' => 'Muvaffaqiyatsiz',
        'refunded' => 'Qaytarildi',
    ],
    'pos_type' => [
        'jowi' => 'Jowi',
        'poster' => 'Poster',
        'iiko' => 'iiko',
        'escpos' => 'ESC/POS printer',
        'manual' => "Qo'lda (panel)",
    ],
    'staff_role' => [
        'platform_admin' => 'Platforma admini',
        'restaurant_owner' => 'Restoran egasi',
        'kitchen_staff' => 'Oshxona xodimi',
    ],

    'eta_range' => 'Taxminiy yetkazib berish: :from–:to daqiqa.',
];
