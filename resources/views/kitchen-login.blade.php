<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ __('messages.kitchen.title') }} — Yetkaz</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="min-h-screen bg-slate-100 flex items-center justify-center p-6 antialiased">
    <div class="w-full max-w-sm">
        <div class="text-center mb-8">
            <h1 class="text-2xl font-bold text-slate-800">{{ __('messages.kitchen.title') }}</h1>
            <p class="text-slate-500 mt-1">Yetkaz</p>
        </div>

        <form method="POST" action="{{ route('kitchen.login') }}"
              class="bg-white rounded-2xl shadow-xl p-6 space-y-5">
            @csrf

            @error('email')
                <div class="bg-red-50 text-red-700 text-sm rounded-lg px-4 py-3">
                    {{ $message }}
                </div>
            @enderror

            <label class="block">
                <span class="text-sm font-medium text-slate-700">{{ __('messages.kitchen.email') }}</span>
                <input type="email" name="email" value="{{ old('email') }}" required autofocus
                       autocomplete="username" inputmode="email"
                       class="mt-1 w-full rounded-lg border-slate-300 border px-4 py-3 text-lg
                              focus:border-emerald-500 focus:ring-emerald-500">
            </label>

            <label class="block">
                <span class="text-sm font-medium text-slate-700">{{ __('messages.kitchen.password') }}</span>
                <input type="password" name="password" required autocomplete="current-password"
                       class="mt-1 w-full rounded-lg border-slate-300 border px-4 py-3 text-lg
                              focus:border-emerald-500 focus:ring-emerald-500">
            </label>

            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="remember" value="1"
                       class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                {{ __('messages.kitchen.remember') }}
            </label>

            <button type="submit"
                    class="w-full bg-emerald-600 hover:bg-emerald-700 text-white text-lg font-semibold
                           rounded-lg py-3 transition">
                {{ __('messages.kitchen.submit') }}
            </button>
        </form>
    </div>
</body>
</html>
