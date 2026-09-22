import { api } from './api';

const { vapidPublicKey } = window.__KITCHEN__;

/** VAPID kalit — brauzer base64url talab qiladi, backend standart base64 beradi. */
function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(base64);

    return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
}

export function pushSupported() {
    return 'serviceWorker' in navigator && 'PushManager' in window && Boolean(vapidPublicKey);
}

async function registerWorker() {
    return navigator.serviceWorker.register('/sw.js');
}

/** Foydalanuvchi ruxsat bergach chaqiriladi — SW ro'yxatdan o'tkazadi, obuna bo'ladi, backend'ga yuboradi. */
export async function enablePush() {
    if (!pushSupported()) {
        throw new Error('Bu brauzer push bildirishnomani qo‘llab-quvvatlamaydi.');
    }

    const permission = await Notification.requestPermission();
    if (permission !== 'granted') {
        throw new Error('Ruxsat berilmadi.');
    }

    const registration = await registerWorker();
    const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
    });

    await api.subscribePush(subscription.toJSON());

    return true;
}

/** "Bildirishnomani o'chirish" — brauzer obunasini ham, backend yozuvini ham tozalaydi. */
export async function disablePush() {
    if (!('serviceWorker' in navigator)) return;

    const registration = await navigator.serviceWorker.getRegistration('/sw.js');
    const subscription = await registration?.pushManager.getSubscription();

    if (subscription) {
        const { endpoint } = subscription.toJSON();
        await subscription.unsubscribe();
        await api.unsubscribePush(endpoint);
    }
}
