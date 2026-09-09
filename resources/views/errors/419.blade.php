<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sessiya yangilanmoqda… — Yetkaz</title>
    <style>
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
            padding:24px;font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
            background:#f1f5f9;color:#1e293b}
        .box{max-width:22rem;text-align:center}
        .spin{width:34px;height:34px;margin:0 auto 18px;border:3px solid #cbd5e1;
            border-top-color:#059669;border-radius:50%;animation:s .8s linear infinite}
        @keyframes s{to{transform:rotate(360deg)}}
        h1{font-size:1.15rem;margin:0 0 6px}
        p{margin:0;color:#64748b;font-size:.95rem}
        a{color:#059669}
        @media(prefers-color-scheme:dark){body{background:#0f172a;color:#e2e8f0}p{color:#94a3b8}}
    </style>
    <script>
        // Foydalanuvchini kelgan sahifasiga qaytaramiz. U yerda "Eslab qolish"
        // cookie'si avtomat qayta kiritadi yoki kerakli login sahifasiga o'tkazadi.
        (function () {
            var origin = location.origin;
            var ref = document.referrer && document.referrer.indexOf(origin) === 0
                ? document.referrer : null;
            var target = ref || '/';
            setTimeout(function () { location.replace(target); }, 1800);
        })();
    </script>
</head>
<body>
    <div class="box">
        <div class="spin"></div>
        <h1>Sessiyangiz muddati tugadi</h1>
        <p>Sahifa yangilanmoqda… Agar o'zi ochilmasa,
           <a href="/">bosh sahifaga o'ting</a>.</p>
    </div>
</body>
</html>
