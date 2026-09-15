import kitchenAlertMp3 from '../assets/kitchen-alert.mp3';
import kitchenAlertM4a from '../assets/kitchen-alert.m4a';

let el = null;
let unlocked = false;

function getElement() {
    if (!el) {
        el = document.createElement('audio');
        el.loop = true;
        el.preload = 'auto';

        // Brauzer <source>larni tartib bo'yicha sinab, birinchi ijro etoladiganini
        // tanlaydi — mp3 asosiy, m4a (AAC) zaxira.
        const mp3 = document.createElement('source');
        mp3.src = kitchenAlertMp3;
        mp3.type = 'audio/mpeg';
        el.appendChild(mp3);

        const m4a = document.createElement('source');
        m4a.src = kitchenAlertM4a;
        m4a.type = 'audio/mp4';
        el.appendChild(m4a);
    }

    return el;
}

/** Foydalanuvchi bosgach chaqiriladi — brauzer autoplay siyosati talab qiladi. */
export function enableSound() {
    const a = getElement();

    // User-gesture ichida bir marta play+pause — keyingi programmatik play()
    // chaqiruvlari (gesture'siz) shu origin uchun ruxsat etiladi.
    const p = a.play();
    if (p?.catch) p.catch(() => {});
    a.pause();
    a.currentTime = 0;

    unlocked = true;
    return unlocked;
}

export function soundReady() {
    return unlocked;
}

/** Signalni boshlaydi (agar allaqachon ijro etilmayotgan bo'lsa). */
export function startAlarm() {
    if (!unlocked) return;
    const a = getElement();
    if (a.paused) {
        const p = a.play();
        if (p?.catch) p.catch(() => {});
    }
}

/** Vaqtincha to'xtatish ("🔕 Ovozni to'xtatish") — pozitsiya saqlanadi. */
export function pauseAlarm() {
    el?.pause();
}

/** To'liq to'xtatish (barcha buyurtma qabul qilingach) — keyingi safar boshidan. */
export function stopAlarm() {
    if (!el) return;
    el.pause();
    el.currentTime = 0;
}
