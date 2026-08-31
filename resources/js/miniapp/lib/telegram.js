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

/** Bot inline tugmasi: `?r=ID`. Deep link: `start_param = restaurant_ID`. */
export function getStartRestaurantId() {
    try {
        const r = new URL(window.location.href).searchParams.get('r');
        if (r && /^\d+$/.test(r)) return Number(r);
    } catch {
        /* ignore */
    }
    const sp = tg?.initDataUnsafe?.start_param || '';
    const m = sp.match(/^restaurant_(\d+)$/);
    return m ? Number(m[1]) : null;
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
export function setMainButton({ text, onClick, visible = true, color, textColor }) {
    const mb = tg?.MainButton;
    if (!mb) return () => {};
    mb.setParams({
        text,
        color: color || undefined,
        text_color: textColor || undefined,
        is_visible: visible,
        is_active: true,
    });
    if (onClick) mb.onClick(onClick);
    return () => {
        if (onClick) mb.offClick(onClick);
        mb.hide();
    };
}

export function isInsideTelegram() {
    return Boolean(tg?.initData);
}
