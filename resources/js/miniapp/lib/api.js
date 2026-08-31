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

async function request(path, params) {
    const url = new URL(`${BASE || window.location.origin}/api${path}`);
    if (params) {
        for (const [k, v] of Object.entries(params)) {
            if (v !== null && v !== undefined && v !== '') url.searchParams.set(k, v);
        }
    }

    let res;
    try {
        res = await fetch(url, {
            headers: {
                Authorization: `tma ${getInitData()}`,
                Accept: 'application/json',
            },
        });
    } catch (e) {
        throw new ApiError('Tarmoq bilan aloqa yo‘q.', 0, null);
    }

    const body = await res.json().catch(() => ({}));
    if (!res.ok) {
        throw new ApiError(body.message || `Xatolik (${res.status})`, res.status, body);
    }
    return body;
}

export const api = {
    me: () => request('/me'),
    districts: () => request('/districts'),
    restaurants: (params) => request('/restaurants', params),
    restaurant: (id) => request(`/restaurants/${id}`),
    menu: (id) => request(`/restaurants/${id}/menu`),
};
