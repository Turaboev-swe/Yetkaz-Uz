import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// VITE_REVERB_* build vaqtida keladi. Docker frontend bosqichida .env yo'q bo'lsa
// (yoki Reverb sozlanmagan bo'lsa) — real-time o'chiriladi, ilova baribir ishlaydi
// (buyurtmalar HTTP polling bilan yangilanadi, App.jsx). Kalit bo'lmasa `new Echo`
// / pusher-js sinxron xato beradi va butun ilova mount bo'lmaydi — shuning uchun guard.
const key = import.meta.env.VITE_REVERB_APP_KEY;

let echo = null;

if (key) {
    window.Pusher = Pusher;

    const scheme = import.meta.env.VITE_REVERB_SCHEME || 'http';
    const port = Number(import.meta.env.VITE_REVERB_PORT || 8080);
    // Production'da Reverb domen portida (443) ochilmaydi — nginx `/reverb/`
    // yo'lini WebSocket'ga proxy qiladi. VITE_REVERB_PATH=/reverb shu holat uchun.
    // Lokalda bo'sh: to'g'ridan-to'g'ri reverb:8080 ga ulanadi.
    const wsPath = import.meta.env.VITE_REVERB_PATH || '';

    echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
        wsPort: port,
        wssPort: port,
        wsPath,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        disableStats: true,
        authEndpoint: '/broadcasting/auth',
        auth: {
            headers: {
                'X-CSRF-TOKEN': window.__KITCHEN__?.csrf ?? '',
                'X-Requested-With': 'XMLHttpRequest',
            },
        },
    });
} else {
    console.warn('[kitchen] VITE_REVERB_APP_KEY topilmadi — real-time o\'chirilgan, polling ishlaydi.');
}

export { echo };
