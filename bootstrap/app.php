<?php

use App\Http\Middleware\UsePanelSession;
use App\Http\Middleware\ValidateTelegramInitData;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Broadcasting auth (/broadcasting/auth) — oshxona (/kitchen) Echo shu yerdan
    // autentifikatsiya qiladi, shuning uchun `yetkaz_staff_session` cookie'si bilan.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        attributes: ['middleware' => ['panel.session:yetkaz_staff_session', 'web']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'telegram.initdata' => ValidateTelegramInitData::class,
            // Panel bo'yicha alohida sessiya cookie'si (EncryptCookies/StartSession dan oldin).
            'panel.session' => UsePanelSession::class,
        ]);

        // initData tekshiruvi `throttle` dan OLDIN ishlashi shart — aks holda
        // rate limiter `$request->user()` ni ko'rmay, limitni IP bo'yicha (barcha
        // foydalanuvchilar uchun umumiy) hisoblaydi. `ThrottleRequests` priority
        // ro'yxatida, `telegram.initdata` esa yo'q — shu sabab uni oldiga qo'yamiz.
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\ThrottleRequests::class,
            prepend: ValidateTelegramInitData::class,
        );

        // `panel.session` `EncryptCookies` / `StartSession` dan OLDIN ishlashi shart
        // (u `config('session.cookie')` ni tanlaydi). Priority ro'yxatiga qo'yamiz —
        // aks holda middleware saralash uni EncryptCookies ortiga suradi.
        $middleware->prependToPriorityList(
            before: \Illuminate\Cookie\Middleware\EncryptCookies::class,
            prepend: UsePanelSession::class,
        );

        // Mini App reverse-proxy / tunnel (cloudflared, ngrok, production LB) ortida
        // ishlaydi — X-Forwarded-Proto ni hisobga olib https URL generatsiya qilsin
        // (aks holda https sahifada http asset = mixed-content bloklanadi).
        $middleware->trustProxies(at: '*');

        // Autentifikatsiyasiz mehmon: /kitchen -> oshxona login, /restaurant -> uning
        // login'i, aks holda admin.
        $middleware->redirectGuestsTo(fn ($request) => match (true) {
            $request->is('kitchen', 'kitchen/*') => '/kitchen/login',
            $request->is('restaurant', 'restaurant/*') => '/restaurant/login',
            default => '/admin/login',
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
