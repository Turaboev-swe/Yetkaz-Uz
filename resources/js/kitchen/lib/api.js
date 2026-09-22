const { csrf } = window.__KITCHEN__;

async function request(path, method = 'GET', body = null) {
    const headers = {
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrf,
        'X-Requested-With': 'XMLHttpRequest',
    };
    if (body) headers['Content-Type'] = 'application/json';

    const res = await fetch(`/kitchen${path}`, {
        method,
        credentials: 'same-origin',
        headers,
        body: body ? JSON.stringify(body) : undefined,
    });

    // Sessiya muddati tugadi (CSRF token eskirdi) — login sahifasiga qaytaramiz.
    // "Eslab qolish" belgilangan bo'lsa u yerda avtomat qayta kiritiladi.
    if (res.status === 419) {
        window.location.assign('/kitchen/login');
        throw new Error('Sessiyangiz muddati tugadi.');
    }

    if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(err.message || `Xatolik ${res.status}`);
    }

    return res.json();
}

export const api = {
    orders: () => request('/orders'),
    couriers: () => request('/couriers'),
    advance: (id, fields = null) => request(`/orders/${id}/advance`, 'PATCH', fields),
    cancel: (id, reason) => request(`/orders/${id}/cancel`, 'PATCH', { reason }),
    subscribePush: (subscription) => request('/push/subscribe', 'POST', subscription),
    unsubscribePush: (endpoint) => request('/push/subscribe', 'DELETE', { endpoint }),
};
