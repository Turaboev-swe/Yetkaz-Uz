<?php

use App\Http\Controllers\KitchenAuthController;
use App\Http\Controllers\KitchenController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
| Telegram Mini App (SPA). Barcha yo'llar bitta blade'ga tushadi — marshrutlashni
| React (react-router) hal qiladi. API `/api/*` da, xuddi shu domenda.
*/
Route::view('/app/{path?}', 'miniapp')->where('path', '.*')->name('miniapp');

/*
| Oshxona paneli — planshetда ochiq turadigan real-time sahifa. Filament emas.
| `staff` guard + `yetkaz_staff_session` cookie (/restaurant bilan bir xil — egasi
| ikkalasini bir sessiyada ochadi). Ruxsat: Staff::canManageKitchen()
| (restaurant_owner YOKI kitchen_staff). kitchen_staff faqat /kitchen/login
| orqali kiradi — Filament panellariga (canAccessPanel) o'tolmaydi.
*/
Route::middleware(['panel.session:yetkaz_staff_session', 'web'])->prefix('kitchen')->group(function () {
    // Kirish — allaqachon kirган (va ruxsatli) bo'lsa controller /kitchen ga qaytaradi.
    Route::get('/login', [KitchenAuthController::class, 'show'])->name('kitchen.login');
    Route::post('/login', [KitchenAuthController::class, 'store']);
    Route::post('/logout', [KitchenAuthController::class, 'destroy'])->name('kitchen.logout');

    Route::middleware('auth:staff')->group(function () {
        Route::get('/', [KitchenController::class, 'page'])->name('kitchen');
        Route::get('/orders', [KitchenController::class, 'orders']);
        Route::patch('/orders/{order}/advance', [KitchenController::class, 'advance']);
    });
});
