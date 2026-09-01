<?php

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
| Session (staff guard) bilan himoyalangan; faqat restaurant_owner / kitchen_staff.
*/
Route::middleware(['web', 'auth:staff'])->prefix('kitchen')->group(function () {
    Route::get('/', [KitchenController::class, 'page'])->name('kitchen');
    Route::get('/orders', [KitchenController::class, 'orders']);
    Route::patch('/orders/{order}/advance', [KitchenController::class, 'advance']);
});
