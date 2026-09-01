let ctx = null;

/** Foydalanuvchi bosgach chaqiriladi — brauzer autoplay siyosati talab qiladi. */
export function enableSound() {
    if (!ctx) {
        const AC = window.AudioContext || window.webkitAudioContext;
        if (AC) ctx = new AC();
    }
    ctx?.resume?.();
    return Boolean(ctx) && ctx.state === 'running';
}

export function soundReady() {
    return Boolean(ctx) && ctx.state === 'running';
}

function tone(freq, start, dur) {
    const o = ctx.createOscillator();
    const g = ctx.createGain();
    o.connect(g);
    g.connect(ctx.destination);
    o.type = 'sine';
    o.frequency.value = freq;
    g.gain.setValueAtTime(0.0001, ctx.currentTime + start);
    g.gain.exponentialRampToValueAtTime(0.35, ctx.currentTime + start + 0.02);
    g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + start + dur);
    o.start(ctx.currentTime + start);
    o.stop(ctx.currentTime + start + dur);
}

/** Yangi buyurtma — ikki tovushli signal. */
export function newOrderChime() {
    if (!soundReady()) return;
    tone(880, 0, 0.28);
    tone(1174, 0.3, 0.4);
}
