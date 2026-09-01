<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Oshxona — {{ $staff->restaurant?->name }}</title>
    <script>
        window.__KITCHEN__ = {
            staffName: @json($staff->name),
            restaurantId: {{ (int) $staff->restaurant_id }},
            restaurantName: @json($staff->restaurant?->name),
            csrf: @json(csrf_token()),
        };
    </script>
    @vite('resources/js/kitchen/main.jsx')
</head>
<body>
    <div id="kitchen-root"></div>
</body>
</html>
