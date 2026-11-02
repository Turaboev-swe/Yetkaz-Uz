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

// Chek chiqmagan buyurtmalarni belgilash: agent o'chiq yoki printer ishlamasa ham (Reverb xatosiga tayanmaydi).
Schedule::command('print:check-unprinted')->everyMinute()->withoutOverlapping();
