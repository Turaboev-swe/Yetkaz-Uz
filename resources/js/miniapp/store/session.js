import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';

/**
 * Joriy sessiya tanlovlari: yetkazish manzili yoki "Olib ketaman".
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
            /** manzil oqimi (A/B) tugadimi — restoranlar ro'yxatiga o'tsa bo'ladi */
            ready: false,

            confirmDelivery: (addressId) => set({ addressId, mode: 'delivery', ready: true }),
            choosePickup: () => set({ addressId: null, mode: 'pickup', ready: true }),
            reset: () => set({ ready: false }),
        }),
        {
            name: 'yetkaz-session',
            storage: createJSONStorage(() => sessionStorage),
        },
    ),
);
