// Mahsulot qoldig'i: savat limiti va menyu/server bilan moslash. Sof funksiyalar (node:test bilan tekshiriladi).
//
// API (ProductResource): `stock` — qoldiq (null = cheklanmagan), `sold_out` — «Tugadi», `low_stock` — «Faqat N ta qoldi!».
// Frontend faqat qulaylik uchun cheklaydi — haqiqiy tekshiruv serverda (POST /orders, tranzaksiyada).

/** null / undefined — cheklanmagan. */
export const isLimited = (stock) => stock !== null && stock !== undefined;

/** Savatga yana bitta qo'shsa bo'ladimi. */
export function canAddMore(stock, soldOut, qty) {
    if (soldOut) return false;
    return !isLimited(stock) || qty < stock;
}

/** Miqdorni qoldiqqa qirqadi (cheklanmagan bo'lsa o'zgarmaydi). */
export function clampQty(qty, stock) {
    return isLimited(stock) ? Math.max(0, Math.min(qty, stock)) : qty;
}

function reconcile(lines, limitFor) {
    const next = {};
    const removed = [];
    const reduced = [];

    for (const [id, line] of Object.entries(lines || {})) {
        const limit = limitFor(Number(id));

        // Ma'lumot yo'q (menyuda topilmadi) — o'zgartirmaymiz: mavjudlikni server hal qiladi.
        if (limit === undefined) {
            next[id] = line;
            continue;
        }

        const qty = limit.soldOut ? 0 : clampQty(line.qty, limit.stock);

        if (qty <= 0) {
            removed.push(line.name);
            continue;
        }
        if (qty < line.qty) reduced.push({ name: line.name, from: line.qty, to: qty });
        next[id] = { ...line, qty, stock: limit.stock };
    }

    return { lines: next, removed, reduced };
}

/** Savat qatorlarini menyudagi joriy qoldiqqa moslaydi. @returns {{lines, removed: string[], reduced: {name, from, to}[]}} */
export function syncLines(lines, products) {
    const byId = new Map((products || []).map((p) => [Number(p.id), p]));

    return reconcile(lines, (id) => {
        const p = byId.get(id);
        return p ? { stock: isLimited(p.stock) ? p.stock : null, soldOut: Boolean(p.sold_out) } : undefined;
    });
}

/** Server rad etgan qatorlarni (`shortages`: [{product_id, available}]) mavjud miqdorga moslaydi. */
export function applyShortages(lines, shortages) {
    const byId = new Map((shortages || []).map((s) => [Number(s.product_id), s]));

    return reconcile(lines, (id) => {
        const s = byId.get(id);
        return s ? { stock: s.available, soldOut: s.available <= 0 } : undefined;
    });
}

/** Mijozga ko'rsatiladigan xabar (o'zbekcha) yoki null. */
export function stockNoticeText({ removed = [], reduced = [] }) {
    const parts = [
        ...reduced.map((r) => `${r.name}: faqat ${r.to} ta qoldi, savat yangilandi.`),
        ...removed.map((name) => `${name} tugadi va savatdan olib tashlandi.`),
    ];

    return parts.length ? parts.join(' ') : null;
}
