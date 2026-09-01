const { csrf } = window.__KITCHEN__;

async function request(path, method = 'GET') {
    const res = await fetch(`/kitchen${path}`, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        throw new Error(body.message || `Xatolik ${res.status}`);
    }

    return res.json();
}

export const api = {
    orders: () => request('/orders'),
    advance: (id) => request(`/orders/${id}/advance`, 'PATCH'),
};
