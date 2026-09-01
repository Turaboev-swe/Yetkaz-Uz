/**
 * Telegram WebApp SDK ustidan yupqa qobiq.
 *
 * - ranglar themeParams'dan (hardcode emas)
 * - BackButton — Telegram'ning o'ziniki (custom chizilmaydi)
 * - MainButton — savat / rasmiylashtirish uchun
 * - HapticFeedback — taom qo'shilganda
 * - initData — API middleware uchun (Telegramsiz brauzerda __DEV_INIT_DATA__)
 */

const tg = typeof window !== 'undefined' ? window.Telegram?.WebApp : undefined;

export function initTelegram() {
    if (!tg) return;
    try {
        tg.ready();
        tg.expand();
        tg.setHeaderColor?.('secondary_bg_color');
        tg.setBackgroundColor?.('bg_color');
        // Menyuni aylantirganda ilova tasodifan yopilib ketmasin.
        tg.disableVerticalSwipes?.();
    } catch {
        /* eski Telegram klientlari — jim o'tamiz */
    }
    applyTheme();
    tg.onEvent?.('themeChanged', applyTheme);
}

function applyTheme() {
    const p = tg?.themeParams || {};
    const s = document.documentElement.style;
    const set = (key, value) => value && s.setProperty(key, value);

    set('--tg-bg', p.bg_color);
    set('--tg-secondary-bg', p.secondary_bg_color);
    set('--tg-section-bg', p.section_bg_color || p.secondary_bg_color);
    set('--tg-text', p.text_color);
    set('--tg-hint', p.hint_color || p.subtitle_text_color);
    set('--tg-link', p.link_color);
    set('--tg-button', p.button_color);
    set('--tg-button-text', p.button_text_color);
    set('--tg-destructive', p.destructive_text_color);

    document.documentElement.dataset.theme = tg?.colorScheme || 'dark';
}

export function getInitData() {
    if (tg?.initData) return tg.initData;
    if (typeof window !== 'undefined' && window.__DEV_INIT_DATA__) return window.__DEV_INIT_DATA__;
    return '';
}

/** initData bormi (Telegram ichida ochilganmi yoki dev initData berilganmi). */
export function hasInitData() {
    return getInitData() !== '';
}

/** Telegram WebApp konteksti umuman mavjudmi (SDK yuklanganmi). */
export function hasTelegramContext() {
    return Boolean(tg);
}

/**
 * Bot qaysi ekranni so'raganini aniqlaydi.
 *
 * WebApp tugmasi URL query beradi: `?screen=restaurants`, `?r=12`.
 * Deep link (t.me/bot/app?startapp=...) `start_param` beradi:
 * `screen_restaurants`, `restaurant_12`.
 *
 * @returns {{screen: string|null, restaurantId: number|null}}
 */
export function getStartTarget() {
    let screen = null;
    let restaurantId = null;

    try {
        const q = new URL(window.location.href).searchParams;
        if (q.get('screen')) screen = q.get('screen');
        const r = q.get('r');
        if (r && /^\d+$/.test(r)) restaurantId = Number(r);
    } catch {
        /* ignore */
    }

    const sp = tg?.initDataUnsafe?.start_param || '';
    const mr = sp.match(/^restaurant_(\d+)$/);
    if (mr) restaurantId = Number(mr[1]);
    const ms = sp.match(/^screen_([a-z]+)$/);
    if (ms) screen = ms[1];

    return { screen, restaurantId };
}

export function haptic(type = 'light') {
    try {
        tg?.HapticFeedback?.impactOccurred(type);
    } catch {
        /* ignore */
    }
}

export function notify(type = 'success') {
    try {
        tg?.HapticFeedback?.notificationOccurred(type);
    } catch {
        /* ignore */
    }
}

/** Telegram BackButton'ni ko'rsatadi; tozalash funksiyasini qaytaradi. */
export function showBackButton(onClick) {
    const bb = tg?.BackButton;
    if (!bb) return () => {};
    bb.onClick(onClick);
    bb.show();
    return () => {
        bb.offClick(onClick);
        bb.hide();
    };
}

export function hideBackButton() {
    tg?.BackButton?.hide();
}

/** Telegram MainButton (savat / rasmiylashtirish). Tozalash funksiyasini qaytaradi. */
export function setMainButton({ text, onClick, visible = true, active = true, color, textColor }) {
    const mb = tg?.MainButton;
    if (!mb) return () => {};
    mb.setParams({
        text,
        color: color || undefined,
        text_color: textColor || undefined,
        is_visible: visible,
        is_active: active,
    });
    if (onClick) mb.onClick(onClick);
    return () => {
        if (onClick) mb.offClick(onClick);
        mb.hide();
    };
}

export function hideMainButton() {
    tg?.MainButton?.hide();
}

export function isInsideTelegram() {
    return Boolean(tg?.initData);
}
