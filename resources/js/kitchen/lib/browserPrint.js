// Brauzer orqali chek chiqarish (pos_type = browser): chek sahifasi yashirin iframe'da yuklanadi,
// rasmlar yuklangach window.print() chaqiriladi. Yorliqdagi `--kiosk-printing` tufayli dialog chiqmaydi.
// Sof funksiyalar (shouldAutoPrint, imagesReady, createPrintQueue) node:test bilan tekshiriladi.

import { api } from './api.js';

export const receiptUrl = (orderId) => `/kitchen/orders/${orderId}/receipt`;

/**
 * "Qabul qilish" muvaffaqiyatli bo'ldimi (new -> accepted) va restoran cheki brauzer orqali chiqadimi.
 * Boshqa o'tishlarda (tayyorlash, yo'lga chiqdi...) chek AVTOMAT chiqmaydi — qo'lda "Chekni qayta chiqarish".
 */
export function shouldAutoPrint(before, after) {
    return Boolean(before && after && before.status === 'new' && after.status === 'accepted' && after.restaurant?.browser_print);
}

/**
 * Hujjatdagi barcha rasmlar yuklanguncha (yoki xato bo'lguncha) kutadi — logo yuklanmay turib chop etilmasin.
 * Rasm hech yuklanmasa ham `timeoutMs` dan keyin davom etadi (chek logosiz bo'lsa ham chiqishi kerak).
 */
export function imagesReady(doc, timeoutMs = 5000) {
    const waits = Array.from(doc.images || [])
        .filter((img) => !img.complete)
        .map(
            (img) =>
                new Promise((resolve) => {
                    img.addEventListener('load', resolve, { once: true });
                    img.addEventListener('error', resolve, { once: true });
                }),
        );

    if (waits.length === 0) return Promise.resolve();

    return Promise.race([Promise.all(waits), new Promise((resolve) => setTimeout(resolve, timeoutMs))]);
}

/**
 * Chop etishlarni ketma-ket bajaradi: bir vaqtda kelgan buyurtmalar iframe'lari bir-birini bosib qo'ymasin.
 * Bir ish xato bersa ham keyingisi davom etadi (xato o'sha chaqiruvchiga qaytadi).
 */
export function createPrintQueue(runOnce) {
    let chain = Promise.resolve();

    return (orderId) => {
        const job = chain.then(() => runOnce(orderId));
        chain = job.catch(() => {});
        return job;
    };
}

// iframe print() dan keyin darhol olib tashlansa, ba'zi brauzerlarda chop etish ishi uziladi.
const FRAME_LIFETIME_MS = 30000;

function printOnce(orderId) {
    return new Promise((resolve, reject) => {
        const frame = document.createElement('iframe');
        frame.setAttribute('aria-hidden', 'true');
        frame.tabIndex = -1;
        // display:none / visibility:hidden ba'zi brauzerlarda iframe'ni chop etmaydi — 0 o'lchamli iframe ishonchli.
        Object.assign(frame.style, { position: 'fixed', right: '0', bottom: '0', width: '0', height: '0', border: '0' });

        let settled = false;
        const finish = (error) => {
            if (settled) return;
            settled = true;
            clearTimeout(loadTimer);
            setTimeout(() => frame.remove(), FRAME_LIFETIME_MS);
            if (error) reject(error);
            else resolve();
        };
        const loadTimer = setTimeout(() => finish(new Error('Chek sahifasi yuklanmadi.')), 20000);

        frame.onload = async () => {
            try {
                const doc = frame.contentDocument;
                // Sessiya tugagan bo'lsa login sahifasi yoki xato sahifasi keladi — chek emas.
                if (!doc || !doc.querySelector('.receipt')) {
                    throw new Error('Chek ochilmadi (sessiya tugagan bo‘lishi mumkin) — sahifani yangilang.');
                }
                await imagesReady(doc);

                frame.contentWindow.focus();
                frame.contentWindow.print();

                // Chop etish chaqirildi — printed_at yoziladi (mijoz uchun emas, monitoring/hisobot uchun).
                await api.receiptPrinted(orderId);
                finish();
            } catch (e) {
                finish(e);
            }
        };

        frame.src = receiptUrl(orderId);
        document.body.appendChild(frame);
    });
}

/** Buyurtma chekini chop etadi (navbat bilan). Xato bo'lsa rad etiladi. */
export const printReceipt = createPrintQueue(printOnce);
