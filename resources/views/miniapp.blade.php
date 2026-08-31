<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#0e1621">
    <title>Yetkaz</title>

    {{-- Telegram WebApp SDK — Mini App API (themeParams, BackButton, MainButton, HapticFeedback) --}}
    <script src="https://telegram.org/js/telegram-web-app.js"></script>

    @if (app()->environment('local') && config('telegram.dev_init_data'))
        {{-- Faqat lokal: brauzerda Telegramsiz sinash uchun imzolangan initData --}}
        <script>window.__DEV_INIT_DATA__ = @json(config('telegram.dev_init_data'));</script>
    @endif

    @vite('resources/js/miniapp/main.jsx')
</head>
<body>
    <div id="root"></div>
</body>
</html>
