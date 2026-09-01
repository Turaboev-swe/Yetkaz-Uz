<?php

/** @var Nutgram $bot */

use App\Telegram\Handlers\IdHandler;
use App\Telegram\Handlers\LanguageCallbackHandler;
use App\Telegram\Handlers\MenuHandler;
use App\Telegram\Handlers\NewAddressHandler;
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

// Restoran egasi uchun: chat ID ni ko'rsatadi (bildirishnoma sozlash).
$bot->onCommand('id', IdHandler::class)
    ->description('Chat ID ni ko\'rsatish');

// Ro'yxatdan o'tgan foydalanuvchi uchun menyu amallari.
$bot->group(function (Nutgram $bot) {
    // "📍 Yangi manzil" tugmasi yuborgan lokatsiya (suhbat faol emas).
    $bot->onLocation(NewAddressHandler::class);

    // Sozlamalar: til tanlash.
    $bot->onCallbackQueryData('lang:{code}', LanguageCallbackHandler::class);

    // Menyu tugmalari (matn) — dispetcher.
    $bot->fallback(MenuHandler::class);
})->middleware(RequireRegistration::class);
