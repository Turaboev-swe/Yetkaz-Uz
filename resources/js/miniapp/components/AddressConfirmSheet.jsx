import BottomSheet from './BottomSheet';
import { resolvedAddress } from '../lib/address';
import { haptic } from '../lib/telegram';

/**
 * A: ilova ochilganda — "Shu manzilga buyurtma berilsinmi?"
 * Manzil qaysi restoranlar ko'rinishini belgilaydi, shuning uchun ro'yxatdan OLDIN.
 */
export default function AddressConfirmSheet({ open, address, onYes, onNo }) {
    const text = resolvedAddress(address);
    const extra = [address?.entrance && `kirish ${address.entrance}`, address?.floor && `qavat ${address.floor}`, address?.apartment && `xonadon ${address.apartment}`]
        .filter(Boolean)
        .join(' · ');

    return (
        <BottomSheet open={open} dismissible={false}>
            <p className="mb-3 text-[15px] font-medium" style={{ color: 'var(--tg-text)' }}>
                Shu manzilga buyurtma berilsinmi?
            </p>

            <div className="mb-4 rounded-xl p-3" style={{ background: 'var(--tg-section-bg)' }}>
                <div className="flex items-start gap-2">
                    <span aria-hidden>📍</span>
                    <span className="text-[15px] font-bold" style={{ color: 'var(--tg-text)' }}>{text}</span>
                </div>
                {extra && (
                    <p className="mt-0.5 pl-6 text-[13px]" style={{ color: 'var(--tg-hint)' }}>{extra}</p>
                )}
            </div>

            <div className="flex gap-2">
                <button
                    onClick={() => {
                        haptic('light');
                        onNo();
                    }}
                    className="h-11 flex-1 rounded-xl text-[15px] font-semibold"
                    style={{ background: 'var(--tg-section-bg)', color: 'var(--tg-text)' }}
                >
                    Yo‘q
                </button>
                <button
                    onClick={() => {
                        haptic('medium');
                        onYes();
                    }}
                    className="h-11 flex-[2] rounded-xl text-[15px] font-semibold"
                    style={{ background: 'var(--tg-button)', color: 'var(--tg-button-text)' }}
                >
                    Ha
                </button>
            </div>
        </BottomSheet>
    );
}
