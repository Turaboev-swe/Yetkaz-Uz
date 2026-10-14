// Reverb'dan kelgan chat xabarlarini ochiq ChatPanel'ga yetkazuvchi kichik pub/sub
// (App.jsx tinglaydi, ChatPanel obuna bo'ladi) — OrderCard orqali prop uzatmaslik uchun.
const listeners = new Map(); // orderId -> Set<fn>

export const chatBus = {
    subscribe(orderId, fn) {
        if (!listeners.has(orderId)) listeners.set(orderId, new Set());
        listeners.get(orderId).add(fn);

        return () => listeners.get(orderId)?.delete(fn);
    },

    publish(orderId, message) {
        listeners.get(orderId)?.forEach((fn) => fn(message));
    },
};
