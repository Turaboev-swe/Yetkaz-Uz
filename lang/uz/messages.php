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

    // Asosiy menyu
    'main_menu' => [
        'title' => 'Asosiy menyu',
        'search' => '🔍 Taom qidirish',
        'restaurants' => '🏪 Restoranlar',
        'orders' => '📋 Buyurtmalarim',
        'settings' => '⚙️ Sozlamalar',
        'coming_soon' => "Bu bo'lim keyingi bosqichda ishga tushadi.",
    ],

    // Sozlamalar
    'settings' => [
        'title' => 'Sozlamalar',
        'language' => 'Til',
        'language_changed' => 'Til o‘zbekchaga o‘zgartirildi.',
        'choose_language' => 'Tilni tanlang:',
    ],

    // Restoranlar ro'yxati (inline, "Buyurtma berish" dan oldin)
    'restaurants' => [
        'pick' => 'Restoranni tanlang:',
        'open_app' => '📋 Barcha restoranlar',
        'none' => 'Hozircha manzilingizga yetkazadigan restoran yo‘q.',
    ],

    // Savat / restoran
    'cart_kept' => 'Savatingiz saqlanadi.',
    'min_order_not_met' => "Minimal buyurtma summasi: :amount so'm.",
    'restaurant_closed' => 'Restoran hozir yopiq.',
    'out_of_radius' => 'Afsuski, bu manzilga yetkazib bera olmaymiz.',

    // Enum yorliqlari
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
