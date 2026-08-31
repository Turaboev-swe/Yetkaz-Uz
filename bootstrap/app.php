<?php

use App\Http\Middleware\ValidateTelegramInitData;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'telegram.initdata' => ValidateTelegramInitData::class,
        ]);

        // Mini App reverse-proxy / tunnel (cloudflared, ngrok, production LB) ortida
        // ishlaydi — X-Forwarded-Proto ni hisobga olib https URL generatsiya qilsin
        // (aks holda https sahifada http asset = mixed-content bloklanadi).
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
