<?php

/** @var Nutgram $bot */

use App\Telegram\Handlers\FeedbackTypeHandler;
use App\Telegram\Handlers\IdHandler;
use App\Telegram\Handlers\KitchenCallbackHandler;
use App\Telegram\Handlers\KitchenCancelHandler;
use App\Telegram\Handlers\KitchenCancelReasonHandler;
use App\Telegram\Handlers\LanguageCallbackHandler;
use App\Telegram\Handlers\MenuHandler;
use App\Telegram\Handlers\NewAddressHandler;
use App\Telegram\Handlers\RateOrderHandler;
use App\Telegram\Handlers\StartHandler;
use App\Telegram\Middleware\RequireRegistration;
use App\Telegram\Middleware\ResolveUser;
use SergiX44\Nutgram\Nutgram;

/*
|--------------------------------------------------------------------------
| Nutgram Handlers
|--------------------------------------------------------------------------
|
| Biznes mantiq handlerlarda emas — Service klasslarida.
|
| Restoranlar ro'yxati botda EMAS — faqat Mini App ichida. Bot menyu tugmalari
| ("🍿 Buyurtma berish", "🏪 Restoranlar") Mini App'ni WebApp tugmasi bilan ochadi.
|
*/

$bot->middleware(ResolveUser::class);

$bot->onCommand('start', StartHandler::class)
    ->description('Botni ishga tushirish');

// Chuqur havola: /start r_12 (QR / restoran), /start phone, /start phone_r_12
// (Mini App'dan telefon uchun qaytish). Nutgram bo'sh va parametrli variantni
// alohida handler sifatida ko'radi — ikkalasi ham StartHandler'ga boradi.
$bot->onCommand('start {ref}', StartHandler::class);

// Restoran egasi uchun: chat ID ni ko'rsatadi (bildirishnoma sozlash).
$bot->onCommand('id', IdHandler::class)
    ->description('Chat ID ni ko\'rsatish');

// Oshxona xodimi: buyurtma statusini bir bosqich oldinga (bot xabaridagi tugma).
// RequireRegistration'дан TASHQARIDA — xodim `users` jadvalidа bo'lmasligi mumkin.
$bot->onCallbackQueryData('kadv:{orderId}:{expected}', KitchenCallbackHandler::class)
    ->where('orderId', '\d+')
    ->where('expected', '[a-z_]+');

// Oshxona xodimi: buyurtmani bekor qilish (accepted/preparing holatida).
$bot->onCallbackQueryData('kcancel:{orderId}:{expected}', KitchenCancelHandler::class)
    ->where('orderId', '\d+')
    ->where('expected', '[a-z_]+');

$bot->onCallbackQueryData('kcreason:{orderId}:{code}', KitchenCancelReasonHandler::class)
    ->where('orderId', '\d+')
    ->where('code', '[a-z]+');

// Mijoz baholovi — yulduzcha (RequireRegistration'dan TASHQARIDA, egalik
// `order.user` bo'yicha tekshiriladi). Izoh esa oddiy matn bilan yoziladi va
// MenuHandler'da PendingRatingStore orqali ushlanadi.
$bot->onCallbackQueryData('rate:{orderId}:{star}', RateOrderHandler::class)
    ->where('orderId', '\d+')
    ->where('star', '[1-5]');

// Ro'yxatdan o'tgan foydalanuvchi uchun menyu amallari.
$bot->group(function (Nutgram $bot) {
    // "📍 Yangi manzil" tugmasi yuborgan lokatsiya (suhbat faol emas).
    $bot->onLocation(NewAddressHandler::class);

    // Sozlamalar: til tanlash.
    $bot->onCallbackQueryData('lang:{code}', LanguageCallbackHandler::class);

    // "💬 Taklif va shikoyat" — tur tanlash (FeedbackHandler yuborgan inline tugma).
    $bot->onCallbackQueryData('feedback:{type}', FeedbackTypeHandler::class)
        ->where('type', '[a-z]+');

    // Menyu tugmalari (matn) — dispetcher.
    $bot->fallback(MenuHandler::class);
})->middleware(RequireRegistration::class);
