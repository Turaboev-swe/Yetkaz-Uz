<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0e1621">
    <meta name="description" content="Sevimli taomlaringiz eshigingizgacha. Telegram orqali buyurtma bering.">

    <link rel="icon" type="image/png" href="{{ asset('images/yetkaz-logo.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/yetkaz-logo.png') }}">

    <meta property="og:type" content="website">
    <meta property="og:title" content="Yetkaz — ovqat yetkazib berish">
    <meta property="og:description" content="Sevimli taomlaringiz eshigingizgacha. Telegram orqali buyurtma bering.">
    <meta property="og:image" content="{{ asset('images/yetkaz-logo.png') }}">

    <title>Yetkaz — ovqat yetkazib berish</title>

    <style>
        :root {
            --bg: #0e1621;
            --text: #ffffff;
            --hint: #8b98a5;
            --accent: #F5A623;
            --accent-press: #d98e12;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            -webkit-tap-highlight-color: transparent;
            -webkit-font-smoothing: antialiased;
            line-height: 1.5;
        }

        .page {
            min-height: 100%;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 32px 20px calc(32px + env(safe-area-inset-bottom));
            background:
                radial-gradient(90% 55% at 50% 0%, rgba(245, 166, 35, 0.13), transparent 70%),
                var(--bg);
        }

        .card { width: 100%; max-width: 380px; text-align: center; }

        .logo {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            display: block;
            margin: 0 auto 16px;
            box-shadow: 0 10px 40px rgba(245, 166, 35, 0.18);
        }

        .wordmark {
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -0.02em;
            margin-bottom: 24px;
        }

        h1 {
            font-size: 22px;
            font-weight: 600;
            letter-spacing: -0.01em;
            margin-bottom: 8px;
        }

        p.lead {
            font-size: 15px;
            color: var(--hint);
            margin-bottom: 32px;
        }

        .btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 16px 24px;
            border-radius: 14px;
            background: var(--accent);
            color: #1b1b18;
            font-size: 17px;
            font-weight: 600;
            text-decoration: none;
            transition: background-color .15s ease, transform .05s ease;
        }

        .btn:hover { background: var(--accent-press); }
        .btn:active { transform: scale(0.985); }
        .btn svg { width: 20px; height: 20px; flex-shrink: 0; }

        .hint {
            margin-top: 18px;
            font-size: 13px;
            color: var(--hint);
        }

        .hint a { color: var(--hint); }

        footer {
            margin-top: 40px;
            font-size: 12px;
            color: #5b6672;
        }

        @media (min-width: 480px) {
            h1 { font-size: 24px; }
            .wordmark { font-size: 30px; }
        }
    </style>
</head>
<body>
    <div class="page">
        <main class="card">
            <img class="logo" src="{{ asset('images/yetkaz-logo.png') }}" alt="Yetkaz" width="120" height="120">
            <div class="wordmark">Yetkaz</div>

            <h1>Sevimli taomlaringiz eshigingizgacha</h1>
            <p class="lead">Telegram orqali buyurtma bering</p>

            <a class="btn" href="https://t.me/Yetkaz_uzbot" rel="noopener">
                <svg viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                    <path d="M21.9 4.3 3.3 11.5c-1.1.4-1.1 1.1-.2 1.4l4.7 1.5 1.8 5.6c.2.5.1.7.6.7.4 0 .6-.2.8-.4l2.3-2.2 4.8 3.5c.9.5 1.5.2 1.7-.8l3.1-14.6c.3-1.3-.5-1.9-1.8-1.6Z"/>
                </svg>
                Botni ochish
            </a>

            <p class="hint">Telegram o‘rnatilmagan bo‘lsa: <a href="https://t.me/Yetkaz_uzbot">@Yetkaz_uzbot</a></p>

            <footer>© {{ date('Y') }} Yetkaz</footer>
        </main>
    </div>
</body>
</html>
