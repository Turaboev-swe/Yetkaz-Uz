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
| `staff` guard + `yetkaz_staff_session` cookie (/restaurant bilan bir xil — egasi
| ikkalasini bir sessiyada ochadi). Faqat restaurant_owner / kitchen_staff.
*/
Route::middleware(['panel.session:yetkaz_staff_session', 'web', 'auth:staff'])->prefix('kitchen')->group(function () {
    Route::get('/', [KitchenController::class, 'page'])->name('kitchen');
    Route::get('/orders', [KitchenController::class, 'orders']);
    Route::patch('/orders/{order}/advance', [KitchenController::class, 'advance']);
});
