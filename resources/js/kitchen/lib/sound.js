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
    o.type = 'square';
    o.frequency.value = freq;
    g.gain.setValueAtTime(0.0001, ctx.currentTime + start);
    g.gain.exponentialRampToValueAtTime(1.0, ctx.currentTime + start + 0.015);
    g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + start + dur);
    o.start(ctx.currentTime + start);
    o.stop(ctx.currentTime + start + dur);
}

/** Yangi buyurtma — bip-bip (tibbiy uskuna uslubidagi, kesuvchi, eng baland ovoz). */
export function newOrderChime() {
    if (!soundReady()) return;
    tone(1046, 0, 0.15);
    tone(1046, 0.22, 0.15);
}
