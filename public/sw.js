// /kitchen uchun Web Push Service Worker. Root'da (/sw.js) — shuning uchun
// butun origin'ni qamrab oladi, faqat /kitchen emas.
//
// Bu fayl statik — build jarayoniga (Vite) kirmaydi, to'g'ridan-to'g'ri
// /sw.js sifatida xizmat qiladi.

self.addEventListener('push', (event) => {
    let payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch {
        payload = { title: '🔔 Yangi buyurtma!', body: event.data ? event.data.text() : '' };
    }

    const title = payload.title || '🔔 Yangi buyurtma!';
    const options = {
        body: payload.body || '',
        icon: payload.icon || '/images/yetkaz-logo.png',
        badge: payload.badge || '/images/yetkaz-badge.png',
        tag: payload.tag,
        data: payload.data || {},
        requireInteraction: payload.requireInteraction ?? true,
        actions: payload.actions || [],
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

// Bildirishnoma bosilganda — /kitchen ochiq bo'lsa shu tabga o'tadi,
// aks holda yangi oyna ochadi.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || '/kitchen';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
            for (const client of windowClients) {
                if (client.url.includes(url) && 'focus' in client) {
                    return client.focus();
                }
            }

            if (self.clients.openWindow) {
                return self.clients.openWindow(url);
            }

            return undefined;
        }),
    );
});
