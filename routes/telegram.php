<?php

/** @var SergiX44\Nutgram\Nutgram $bot */

use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;

/*
|--------------------------------------------------------------------------
| Nutgram Handlers
|--------------------------------------------------------------------------
|
| Bu yerda Telegram handlerlari ro'yxatdan o'tkaziladi. Ular
| NutgramServiceProvider tomonidan yuklanadi.
|
| Hozircha faqat ulanishni tekshirish uchun /start handleri bor —
| u foydalanuvchiga salom yozadi va telegram_id sini qaytaradi.
| Baza bilan ishlamaydi (ro'yxatdan o'tish oqimi keyingi bosqichda).
|
*/

$bot->onCommand('start', function (Nutgram $bot) {
    $user = $bot->user();
    $name = trim(($user?->first_name ?? '').' '.($user?->last_name ?? ''));
    $greeting = $name !== '' ? "Salom, {$name}!" : 'Salom!';

    // Ulanishni tekshirish uchun — 2-bosqichda olib tashlanadi.
    Log::info('Telegram /start', ['telegram_id' => $bot->userId(), 'username' => $user?->username]);

    $bot->sendMessage(
        $greeting." 👋\n\n".
        "Yetkaz botiga ulanish ishlayapti ✅\n".
        "Sizning Telegram ID: {$bot->userId()}"
    );
})->description('Botni ishga tushirish');
