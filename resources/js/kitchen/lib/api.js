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

    if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(err.message || `Xatolik ${res.status}`);
    }

    return res.json();
}

export const api = {
    orders: () => request('/orders'),
    advance: (id, fields = null) => request(`/orders/${id}/advance`, 'PATCH', fields),
};
