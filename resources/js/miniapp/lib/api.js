import { getInitData } from './telegram';

/** Xuddi shu domen — blade `/app` bilan API `/api` bir joyda. */
const BASE = import.meta.env.VITE_API_URL || '';

export class ApiError extends Error {
    constructor(message, status, body) {
        super(message);
        this.status = status;
        this.body = body;
    }
}

async function request(path, { params, method = 'GET', body } = {}) {
    const url = new URL(`${BASE || window.location.origin}/api${path}`);
    if (params) {
        for (const [k, v] of Object.entries(params)) {
            if (v !== null && v !== undefined && v !== '') url.searchParams.set(k, v);
        }
    }

    let res;
    try {
        res = await fetch(url, {
            method,
            headers: {
                Authorization: `tma ${getInitData()}`,
                Accept: 'application/json',
                ...(body ? { 'Content-Type': 'application/json' } : {}),
            },
            body: body ? JSON.stringify(body) : undefined,
        });
    } catch (e) {
        throw new ApiError('Tarmoq bilan aloqa yo‘q.', 0, null);
    }

    const payload = await res.json().catch(() => ({}));
    if (!res.ok) {
        const detail = payload.reason ? ` (${payload.reason})` : '';
        throw new ApiError((payload.message || `Xatolik ${res.status}`) + detail, res.status, payload);
    }
    return payload;
}

export const api = {
    me: () => request('/me'),
    districts: () => request('/districts'),
    restaurants: (params) => request('/restaurants', { params }),
    restaurant: (id, addressId) => request(`/restaurants/${id}`, { params: addressId ? { address_id: addressId } : undefined }),
    menu: (id) => request(`/restaurants/${id}/menu`),
    addresses: () => request('/addresses'),
    createAddress: (body) => request('/addresses', { method: 'POST', body }),
    reverse: (lat, lng) => request('/geo/reverse', { params: { lat, lng } }),
    createOrder: (body) => request('/orders', { method: 'POST', body }),
    order: (id) => request(`/orders/${id}`),
};
