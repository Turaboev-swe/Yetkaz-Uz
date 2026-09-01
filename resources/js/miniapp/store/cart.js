import { create } from 'zustand';
import { persist } from 'zustand/middleware';

/**
 * Savat mahalliy (localStorage). Har restoran uchun alohida — foydalanuvchi
 * restoran almashtirsa, oldingi savat saqlanadi.
 *
 * carts: { [restaurantId]: { [productId]: { qty, price, name } } }
 *
 * Buyurtma yaratilgach shu restoran savati tozalanadi (clear).
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

            clear: (rid) =>
                set((state) => {
                    const carts = { ...state.carts };
                    delete carts[rid];
                    return { carts };
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

/** @returns {{product_id:number, qty:number}[]} — API uchun */
export function cartItems(carts, rid) {
    return Object.entries(carts?.[rid] || {}).map(([productId, line]) => ({
        product_id: Number(productId),
        qty: line.qty,
    }));
}

/** @returns {{product_id:number, name:string, price:number, qty:number}[]} — ko'rsatish uchun */
export function cartLines(carts, rid) {
    return Object.entries(carts?.[rid] || {}).map(([productId, line]) => ({
        product_id: Number(productId),
        name: line.name,
        price: line.price,
        qty: line.qty,
    }));
}
