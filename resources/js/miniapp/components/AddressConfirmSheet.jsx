import BottomSheet from './BottomSheet';
import { addressLines } from '../lib/address';
import { haptic } from '../lib/telegram';

/**
 * A: ilova ochilganda — "Shu manzilga buyurtma berilsinmi?"
 * Manzil qaysi restoranlar ko'rinishini belgilaydi, shuning uchun ro'yxatdan OLDIN.
 */
export default function AddressConfirmSheet({ open, address, onYes, onNo }) {
    const { title, subtitle } = addressLines(address);

    return (
        <BottomSheet open={open} dismissible={false}>
            <p className="mb-3 text-[15px] font-medium" style={{ color: 'var(--tg-text)' }}>
                Shu manzilga buyurtma berilsinmi?
            </p>

            <div className="mb-4 rounded-xl p-3" style={{ background: 'var(--tg-section-bg)' }}>
                <div className="flex items-center gap-2">
                    <span aria-hidden>📍</span>
                    <span className="text-[15px] font-bold" style={{ color: 'var(--tg-text)' }}>{title}</span>
                </div>
                {subtitle && (
                    <p className="mt-0.5 pl-6 text-[13px]" style={{ color: 'var(--tg-hint)' }}>{subtitle}</p>
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
