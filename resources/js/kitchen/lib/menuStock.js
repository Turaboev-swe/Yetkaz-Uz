// Oshxona "Menyu" bo'limi: tugma holati va qidiruv (sof funksiyalar — node --test bilan tekshiriladi).
//
// API (KitchenMenuController): har taomda `stock` (null = cheklanmagan), `sold_out` (qoldiq 0), `hidden` (restoran panelida yashirilgan).

/** Tugma yorlig'i: qoldiq 0 bo'lsa «Tugadi», aks holda «Bor» (+ qoldiq soni, agar cheklangan bo'lsa). */
export function buttonLabel(product) {
    if (product.sold_out) return 'Tugadi';
    return product.stock === null || product.stock === undefined ? 'Bor' : `Bor · ${product.stock} ta`;
}

/** Tugma bosilganda yuboriladigan holat: hozir «Bor» bo'lsa — «Tugadi» qilinadi va aksincha. */
export function nextSoldOut(product) {
    return !product.sold_out;
}

/** Nom bo'yicha qidiruv (harf registriga va chekka bo'shliqlarga befarq); bo'sh qidiruv — hammasi. Bo'sh kategoriya tushadi. */
export function filterCategories(categories, query) {
    const q = String(query ?? '').trim().toLocaleLowerCase();
    if (q === '') return categories;

    return categories
        .map((c) => ({ ...c, products: c.products.filter((p) => p.name.toLocaleLowerCase().includes(q)) }))
        .filter((c) => c.products.length > 0);
}

/** Javobdagi yangilangan taomni ro'yxatdagi o'rniga qo'yadi (boshqa taomlar o'zgarmaydi). */
export function replaceProduct(categories, updated) {
    return categories.map((c) => ({
        ...c,
        products: c.products.map((p) => (p.id === updated.id ? updated : p)),
    }));
}
