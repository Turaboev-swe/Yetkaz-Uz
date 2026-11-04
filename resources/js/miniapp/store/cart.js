import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import { applyShortages, canAddMore, stockNoticeText, syncLines } from '../lib/stock';

/**
 * Savat mahalliy (localStorage). Har restoran uchun alohida — foydalanuvchi
 * restoran almashtirsa, oldingi savat saqlanadi.
 *
 * carts: { [restaurantId]: { [productId]: { qty, price, name, photo_url?, logo_url?, stock? } } }
 *   `stock` — oxirgi ma'lum qoldiq (null/yo'q = cheklanmagan): savatga undan ko'p qo'shib bo'lmaydi.
 *   Haqiqiy tekshiruv serverda (POST /orders) — bu faqat qulaylik.
 * notice: qoldiq o'zgargani uchun savat yangilangani haqida xabar (saqlanmaydi; ekranlar ko'rsatib, yopadi).
 *
 * Buyurtma yaratilgach shu restoran savati tozalanadi (clear).
 */
export const useCart = create(
    persist(
        (set, get) => ({
            carts: {},
            notice: null,

            // `logoUrl` — restoran logosi (rasmsiz taom tarelkasi uchun), savatga qo'shilayotganda saqlanadi.
            add: (rid, product, logoUrl) =>
                set((state) => {
                    const line = state.carts[rid]?.[product.id];
                    // Menyudan kelgan mahsulotda `stock` bor; savatdagi "+" da yo'q — qatordagi oxirgi ma'lum qoldiq ishlatiladi.
                    const stock = product.stock !== undefined ? product.stock : (line?.stock ?? null);
                    if (!canAddMore(stock, product.sold_out, line?.qty || 0)) return state;

                    return {
                        carts: {
                            ...state.carts,
                            [rid]: {
                                ...state.carts[rid],
                                [product.id]: {
                                    qty: (line?.qty || 0) + 1,
                                    stock,
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

            // Menyudagi joriy qoldiqqa moslash (menyu/savat ochilganda). Ortiqchasi qirqiladi, tugagani olib tashlanadi.
            syncStock: (rid, products) => {
                const res = syncLines(get().carts[rid], products);
                const notice = stockNoticeText(res);
                set((state) => ({
                    carts: { ...state.carts, [rid]: res.lines },
                    notice: notice ?? state.notice,
                }));
                return res;
            },

            // Server buyurtmani rad etdi (422 `shortages`) — savatni mavjud miqdorga moslash.
            applyShortages: (rid, shortages) => {
                const res = applyShortages(get().carts[rid], shortages);
                const notice = stockNoticeText(res);
                set((state) => ({
                    carts: { ...state.carts, [rid]: res.lines },
                    notice: notice ?? state.notice,
                }));
                return res;
            },

            clearNotice: () => set({ notice: null }),
        }),
        // Faqat savat saqlanadi; xabar (notice) har sessiyada yangidan.
        { name: 'yetkaz-cart', version: 1, partialize: (state) => ({ carts: state.carts }) },
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

/** @returns {{product_id:number, name:string, price:number, qty:number, stock:?number, photo_url:?string, logo_url:?string}[]} — ko'rsatish uchun */
export function cartLines(carts, rid) {
    return Object.entries(carts?.[rid] || {}).map(([productId, line]) => ({
        product_id: Number(productId),
        name: line.name,
        price: line.price,
        qty: line.qty,
        stock: line.stock ?? null,
        photo_url: line.photo_url ?? null,
        logo_url: line.logo_url ?? null,
    }));
}
