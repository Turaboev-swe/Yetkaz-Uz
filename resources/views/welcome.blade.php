<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0e1621">
    <meta name="description" content="Qo'rg'ontepa hududida tez va qulay ovqat yetkazib berish. Buyurtma Telegram bot orqali.">

    <meta property="og:type" content="website">
    <meta property="og:title" content="Yetkaz — ovqat yetkazib berish">
    <meta property="og:description" content="Qo'rg'ontepa hududida tez va qulay ovqat yetkazib berish.">

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

        .mark {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 28px;
        }

        .mark__dot {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            background: var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .mark__dot svg { width: 26px; height: 26px; display: block; }

        .mark__name {
            font-size: 26px;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        h1 {
            font-size: 22px;
            font-weight: 600;
            letter-spacing: -0.01em;
            margin-bottom: 12px;
        }

        p.lead {
            font-size: 16px;
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
            .mark__name { font-size: 28px; }
        }
    </style>
</head>
<body>
    <div class="page">
        <main class="card">
            <div class="mark">
                <span class="mark__dot" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M4 10.5C4 7 6.7 4 12 4C17.3 4 20 7 20 10.5C20 12.9 18.6 14.6 16.7 16.1C15.3 17.2 13.9 18.2 12.9 19.4C12.5 19.9 11.5 19.9 11.1 19.4C10.1 18.2 8.7 17.2 7.3 16.1C5.4 14.6 4 12.9 4 10.5Z" fill="#1b1b18"/>
                        <circle cx="12" cy="10.5" r="2.6" fill="#F5A623"/>
                    </svg>
                </span>
                <span class="mark__name">Yetkaz</span>
            </div>

            <h1>Qo‘rg‘ontepa hududida ovqat yetkazib berish</h1>
            <p class="lead">Tez va qulay — buyurtma Telegram bot orqali, bir necha bosishda.</p>

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
