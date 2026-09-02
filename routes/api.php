<?php

use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\GeoController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\RestaurantController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mini App API
|--------------------------------------------------------------------------
|
| Barcha endpointlar `telegram.initdata` middleware ostida — har so'rov
| Telegram WebApp initData imzosi bilan autentifikatsiya qilinadi.
|
| Rate limit (PROD-1) — foydalanuvchi bo'yicha, Redis'da:
|   - throttle:api-read   60/daqiqa  (o'qish: restoranlar, menyu, qidiruv, ETA)
|   - throttle:addresses  20/daqiqa  (manzil yozish)
|   - throttle:orders      5/daqiqa  (buyurtma yaratish)
| Limiterlar AppServiceProvider::configureRateLimiting() da aniqlangan.
|
*/

Route::middleware('telegram.initdata')->group(function () {
    // --- O'qish (60/daqiqa) ---
    Route::middleware('throttle:api-read')->group(function () {
        Route::get('/me', [MeController::class, 'show']);
        Route::get('/addresses', [AddressController::class, 'index']);

        Route::get('/regions', [GeoController::class, 'regions']);
        Route::get('/districts', [GeoController::class, 'districts']);
        Route::get('/geo/reverse', [GeoController::class, 'reverse']);

        Route::get('/restaurants', [RestaurantController::class, 'index']);
        Route::get('/restaurants/{restaurant}', [RestaurantController::class, 'show']);
        Route::get('/restaurants/{restaurant}/menu', [RestaurantController::class, 'menu']);

        // ETA taxmini — buyurtma yaratmaydi, rasmiylashtirish ekranida bir necha
        // marta chaqiriladi, shuning uchun o'qish tarifida.
        Route::post('/orders/estimate', [OrderController::class, 'estimate']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);

        Route::get('/search', [SearchController::class, 'index']);
    });

    // --- Manzil yozish (20/daqiqa) ---
    Route::middleware('throttle:addresses')->group(function () {
        Route::post('/addresses', [AddressController::class, 'store']);
        Route::patch('/addresses/{address}', [AddressController::class, 'update']);
        Route::delete('/addresses/{address}', [AddressController::class, 'destroy']);
    });

    // --- Buyurtma yaratish (5/daqiqa) ---
    Route::middleware('throttle:orders')->group(function () {
        Route::post('/orders', [OrderController::class, 'store']);
    });
});

/*
| Telegram webhook (PROD-2 / PROD-6). initData EMAS — Telegram serveri POST qiladi.
| Ikki qatlamli tekshiruv: URL path segmenti + X-Telegram-Bot-Api-Secret-Token
| sarlavhasi, ikkalasi ham TELEGRAM_WEBHOOK_SECRET bilan. Mos kelmasa 404
| (endpoint mavjudligini ham yashiradi). TELEGRAM_WEBHOOK_SECRET bo'sh bo'lsa
| (hozirgi holat — domen yo'q, bot polling) — har doim 404.
| Faollashtirish: php artisan telegram:webhook:set (faqat production + https).
*/
Route::post('/telegram/webhook/{token}', TelegramWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('telegram.webhook');

/*
| Print agent (oshxona kompyuteri) — sessiyasiz, Bearer token bilan.
*/
Route::prefix('agent')->group(function () {
    Route::post('/broadcasting/auth', [AgentController::class, 'broadcastAuth']);
    Route::get('/orders/pending', [AgentController::class, 'pending']);
    Route::post('/orders/{order}/printed', [AgentController::class, 'confirmPrinted']);
});
