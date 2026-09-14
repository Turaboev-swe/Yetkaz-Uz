import BottomSheet from './BottomSheet';
import { resolvedAddress } from '../lib/address';
import { haptic } from '../lib/telegram';

/**
 * C: manzil tasdiqlangandan/tanlangandan KEYIN, restoranlar ro'yxatidan OLDIN —
 * yetkazib berish yoki olib ketishni tanlash. Bu tanlov ro'yxat filtrlashiga
 * (delivery_type) ta'sir qiladi.
 *
 * Ikkala rejimda ham xuddi shu manzil — olib ketishda ham restoranlar shu
 * nuqtadan masofaga qarab tartiblanadi (faqat restoranning o'z yetkazish
 * radiusi qo'llanmaydi).
 */
export default function DeliveryModeSheet({ open, onClose, dismissible = true, address, onDelivery, onPickup }) {
    const row = 'flex w-full items-center gap-3 rounded-xl p-4 text-left transition active:scale-[0.99]';

    return (
        <BottomSheet open={open} onClose={onClose} dismissible={dismissible}>
            <p className="mb-1 text-[15px] font-semibold" style={{ color: 'var(--tg-text)' }}>
                Qanday olasiz?
            </p>
            {address && (
                <p className="mb-3 truncate text-[12px]" style={{ color: 'var(--tg-hint)' }}>
                    📍 {resolvedAddress(address)}
                </p>
            )}

            <div className="space-y-2">
                <button
                    onClick={() => {
                        haptic('medium');
                        onDelivery();
                    }}
                    className={row}
                    style={{ background: 'var(--tg-button)', color: 'var(--tg-button-text)' }}
                >
                    <span aria-hidden className="text-xl">🛵</span>
                    <span className="flex-1 text-[15px] font-semibold">Yetkazib berish</span>
                </button>

                <button
                    onClick={() => {
                        haptic('medium');
                        onPickup();
                    }}
                    className={row}
                    style={{ background: 'var(--tg-section-bg)', color: 'var(--tg-text)' }}
                >
                    <span aria-hidden className="text-xl">🛍</span>
                    <span className="flex-1 text-[15px] font-semibold">Olib ketaman</span>
                </button>
            </div>
        </BottomSheet>
    );
}
