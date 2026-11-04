<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Restoranlar obunasi: har oyning 1-kuni 06:00 (Asia/Tashkent) da joriy oy (fixed, oldindan) va o'tgan oy (percent) hisob-kitoblari.
Schedule::command('billing:generate-invoices')
    ->monthlyOn(1, '06:00')
    ->timezone(config('app.display_timezone'))
    ->withoutOverlapping();

// Joriy tarif (restaurants.billing_*) oy almashganda kelajak yozuviga o'tishi uchun: har kuni 00:05 (Asia/Tashkent).
Schedule::command('billing:sync-tariffs')
    ->dailyAt('00:05')
    ->timezone(config('app.display_timezone'))
    ->withoutOverlapping();

// Chek chiqmagan buyurtmalarni belgilash: agent o'chiq yoki printer ishlamasa ham (Reverb xatosiga tayanmaydi).
Schedule::command('print:check-unprinted')->everyMinute()->withoutOverlapping();

// Kunlik qoldiq: har daqiqa `daily_reset_time` (Asia/Tashkent) kelgan mahsulotlarda stock_quantity = daily_stock.
// Vaqt zonasi va «kuniga bir marta» mantig'i StockService::resetDaily() da (scheduler soati UTC bo'lsa ham to'g'ri).
Schedule::command('stock:reset-daily')->everyMinute()->withoutOverlapping();
