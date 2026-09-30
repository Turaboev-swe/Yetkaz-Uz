<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Oshxona — {{ $restaurants->pluck('name')->join(' · ') }}</title>
    <script>
        window.__KITCHEN__ = {
            staffName: @json($staff->name),
            // Biriktirilgan barcha restoranlar (asosiysi birinchi) — har biriga Reverb kanali.
            restaurants: @json($restaurants),
            csrf: @json(csrf_token()),
            vapidPublicKey: @json(config('webpush.vapid.public_key')),
            pushSubscribed: {{ $staff->pushSubscriptions()->exists() ? 'true' : 'false' }},
        };
    </script>
    @vite('resources/js/kitchen/main.jsx')
</head>
<body>
    <div id="kitchen-root"></div>
</body>
</html>
