<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Restoranlar obunasi: har oyning 1-kuni 06:00 (Asia/Tashkent) da o'tgan oy hisob-kitoblari.
Schedule::command('billing:generate-invoices')
    ->monthlyOn(1, '06:00')
    ->timezone(config('app.display_timezone'))
    ->withoutOverlapping();
