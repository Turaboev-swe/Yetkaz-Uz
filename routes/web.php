<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
| Telegram Mini App (SPA). Barcha yo'llar bitta blade'ga tushadi — marshrutlashni
| React (react-router) hal qiladi. API `/api/*` da, xuddi shu domenda.
*/
Route::view('/app/{path?}', 'miniapp')->where('path', '.*')->name('miniapp');
