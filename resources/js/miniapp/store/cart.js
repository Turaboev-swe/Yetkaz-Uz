import { create } from 'zustand';
import { persist } from 'zustand/middleware';

/**
 * Savat mahalliy (localStorage). Har restoran uchun alohida — foydalanuvchi
 * restoran almashtirsa, oldingi savat saqlanadi.
 *
 * carts: { [restaurantId]: { [productId]: { qty, price, name } } }
 *
 * Backend savati (Redis) va rasmiylashtirish — 5-ekranda.
 */
export const useCart = create(
    persist(
        (set) => ({
            carts: {},

            add: (rid, product) =>
                set((state) => {
                    const line = state.carts[rid]?.[product.id];
                    return {
                        carts: {
                            ...state.carts,
                            [rid]: {
                                ...state.carts[rid],
                                [product.id]: {
                                    qty: (line?.qty || 0) + 1,
                                    price: product.price,
                                    name: product.name,
                                },
                            },
                        },
                    };
                }),

            remove: (rid, productId) =>
                set((state) => {
                    const cart = { ...state.carts[rid] };
                    const line = cart[productId];
                    if (!line) return state;
                    if (line.qty <= 1) delete cart[productId];
                    else cart[productId] = { ...line, qty: line.qty - 1 };
                    return { carts: { ...state.carts, [rid]: cart } };
                }),
        }),
        { name: 'yetkaz-cart', version: 1 },
    ),
);

export function cartQty(carts, rid, productId) {
    return carts?.[rid]?.[productId]?.qty || 0;
}

export function cartCount(carts, rid) {
    return Object.values(carts?.[rid] || {}).reduce((n, line) => n + line.qty, 0);
}

export function cartTotal(carts, rid) {
    return Object.values(carts?.[rid] || {}).reduce((n, line) => n + line.qty * line.price, 0);
}
