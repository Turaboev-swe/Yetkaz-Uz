import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';

/**
 * Joriy sessiya tanlovlari: manzil va yetkazish/olib ketish rejimi.
 *
 * Oqim: manzil tasdiqlash/tanlash (setAddress) -> rejim tanlash
 * (confirmDelivery/choosePickup, `ready: true` qiladi — shundan keyin
 * restoranlar ro'yxati ko'rinadi). Ikkala rejimda ham `addressId` saqlanadi —
 * olib ketishda ham restoranlar shu manzildan masofaga qarab tartiblanadi.
 *
 * sessionStorage'da — ilova to'liq yopilib qayta ochilsa tozalanadi
 * (shunda manzil tasdig'i qaytadan so'raladi). Restoran <-> menyu
 * navigatsiyasida saqlanadi.
 */
export const useSession = create(
    persist(
        (set) => ({
            /** null = hali tanlanmagan/tasdiqlanmagan */
            addressId: null,
            /** 'delivery' | 'pickup' */
            mode: 'delivery',
            /** manzil oqimi tugadimi (manzil + rejim tanlandi) — restoranlar ro'yxatiga o'tsa bo'ladi */
            ready: false,
            /** mehmon rejimi (QR / deep-link, ro'yxatdan o'tmagan) */
            guest: false,

            /** Manzil tasdiqlandi/tanlandi — rejim hali so'ralmagan (keyingi qadam). */
            setAddress: (addressId) => set({ addressId }),
            confirmDelivery: (addressId) => set((s) => ({ addressId: addressId ?? s.addressId, mode: 'delivery', ready: true })),
            /** addressId berilmasa — joriy manzil saqlanadi (masofa bo'yicha tartiblash uchun kerak). */
            choosePickup: (addressId) => set((s) => ({ addressId: addressId ?? s.addressId, mode: 'pickup', ready: true })),
            /** mehmon: menyu ochiq, "olib ketish" oldindan tanlangan (manzil so'ralmaydi) */
            startGuest: () => set({ guest: true, addressId: null, mode: 'pickup', ready: true }),
            reset: () => set({ ready: false }),
        }),
        {
            name: 'yetkaz-session',
            storage: createJSONStorage(() => sessionStorage),
        },
    ),
);
