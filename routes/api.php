<?php

use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\GeoController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\RestaurantController;
use App\Http\Controllers\Api\SearchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mini App API
|--------------------------------------------------------------------------
|
| Barcha endpointlar `telegram.initdata` middleware ostida — har so'rov
| Telegram WebApp initData imzosi bilan autentifikatsiya qilinadi.
|
*/

Route::middleware('telegram.initdata')->group(function () {
    Route::get('/me', [MeController::class, 'show']);

    Route::get('/addresses', [AddressController::class, 'index']);
    Route::post('/addresses', [AddressController::class, 'store']);
    Route::patch('/addresses/{address}', [AddressController::class, 'update']);
    Route::delete('/addresses/{address}', [AddressController::class, 'destroy']);

    Route::get('/regions', [GeoController::class, 'regions']);
    Route::get('/districts', [GeoController::class, 'districts']);

    Route::get('/restaurants', [RestaurantController::class, 'index']);
    Route::get('/restaurants/{restaurant}/menu', [RestaurantController::class, 'menu']);

    Route::get('/search', [SearchController::class, 'index']);
});
