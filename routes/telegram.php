<?php

/** @var Nutgram $bot */

use App\Telegram\Handlers\MenuHandler;
use App\Telegram\Handlers\StartHandler;
use App\Telegram\Middleware\ResolveUser;
use SergiX44\Nutgram\Nutgram;

/*
|--------------------------------------------------------------------------
| Nutgram Handlers
|--------------------------------------------------------------------------
|
| Telegram handlerlari. NutgramServiceProvider tomonidan yuklanadi.
| Biznes mantiq handlerlarda emas — Service klasslarida.
|
| 2-bosqich: ro'yxatdan o'tish oqimi (telefon -> ism -> lokatsiya).
|
*/

$bot->middleware(ResolveUser::class);

$bot->onCommand('start', StartHandler::class)
    ->description('Botni ishga tushirish');

// Buyruq bo'lmagan xabarlar (menyu tugmalari, matn) — suhbat faol bo'lmaganda.
$bot->fallback(MenuHandler::class);
