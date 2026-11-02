// Print agent holati: holat o'tishlarini aniqlash (sof funksiyalar — node:test bilan tekshiriladi).

/**
 * Faqat print agenti sozlangan restoranlar (uses_printer) hisobga olinadi — printeri yo'q restoranda
 * hech qanday banner/signal bo'lmaydi.
 */
export function printerRestaurants(restaurants = []) {
    return restaurants.filter((r) => r.uses_printer);
}

/** Agenti jim (o'chiq yoki hech qachon ulanmagan) restoranlar — banner shular uchun. */
export function offlineAgents(restaurants = []) {
    return printerRestaurants(restaurants).filter((r) => !r.agent_online);
}

/**
 * Oldingi holat (restoran id -> 'online' | 'offline') va yangi javobdan: yangi holat va
 * "ishlayapti -> o'chiq" o'tishi bo'lganmi. Signal FAQAT shu o'tishda chalinadi:
 *  - birinchi yuklashda (oldingi holat noma'lum) signal yo'q — faqat banner;
 *  - o'chiq holatda turganda qayta so'rovlar signal bermaydi;
 *  - agent qayta ulansa holat 'online' bo'ladi (banner yo'qoladi) va keyingi o'chishda yana bir marta chalinadi.
 *
 * @returns {{ next: Record<number, 'online'|'offline'>, wentOffline: boolean }}
 */
export function agentTransition(prev = {}, restaurants = []) {
    const next = {};
    let wentOffline = false;

    for (const r of printerRestaurants(restaurants)) {
        const state = r.agent_online ? 'online' : 'offline';
        next[r.id] = state;
        if (prev[r.id] === 'online' && state === 'offline') wentOffline = true;
    }

    return { next, wentOffline };
}

/**
 * "Chek chiqmadi" yangi buyurtma uchun alohida signal: faqat soni oshganda va agent o'chiq banneri
 * ko'rinmayotganda (agent o'chiq bo'lsa, buyurtmalar chiqmasligi kutilgan oqibat — banner yetarli).
 */
export function shouldPingFailed(prevFailed, failedCount, offlineCount) {
    return failedCount > prevFailed && offlineCount === 0;
}
