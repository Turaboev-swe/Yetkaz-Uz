import { create } from 'zustand';
import { persist } from 'zustand/middleware';

/**
 * Savat mahalliy (localStorage). Har restoran uchun alohida — foydalanuvchi
 * restoran almashtirsa, oldingi savat saqlanadi.
 *
 * carts: { [restaurantId]: { [productId]: { qty, price, name, photo_url?, logo_url? } } }
 *
 * Buyurtma yaratilgach shu restoran savati tozalanadi (clear).
 */
export const useCart = create(
    persist(
        (set) => ({
            carts: {},

            // `logoUrl` — restoran logosi (rasmsiz taom tarelkasi uchun), savatga qo'shilayotganda saqlanadi.
            add: (rid, product, logoUrl) =>
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
                                    // Savatdagi rasm uchun (ProductImage). Eski (rasm saqlanmagan) qatorlarda
                                    // yo'q — tarelka placeholder chiqadi; savatdagi "+" uni o'chirib yubormaydi.
                                    photo_url: product.photo_url ?? line?.photo_url ?? null,
                                    // Eski qatorlarda yo'q -> ProductImage Yetkaz logosini ko'rsatadi.
                                    logo_url: logoUrl ?? line?.logo_url ?? null,
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

/** @returns {{product_id:number, name:string, price:number, qty:number, photo_url:?string, logo_url:?string}[]} — ko'rsatish uchun */
export function cartLines(carts, rid) {
    return Object.entries(carts?.[rid] || {}).map(([productId, line]) => ({
        product_id: Number(productId),
        name: line.name,
        price: line.price,
        qty: line.qty,
        photo_url: line.photo_url ?? null,
        logo_url: line.logo_url ?? null,
    }));
}
