import { useNavigate } from 'react-router-dom';
import BottomSheet from './BottomSheet';
import { addressLines } from '../lib/address';
import { haptic } from '../lib/telegram';

/**
 * B: manzil tanlash. Yuqoridan pastga —
 *   saqlangan manzillar (joriysi belgilangan) / 🛍 Olib ketaman / ➕ Yangi manzil
 * Nomlar DOIM o'zbekcha (districts jadvalidan).
 */
export default function AddressPickerSheet({ open, onClose, dismissible = true, addresses, currentId, mode, onPickAddress, onPickup }) {
    const navigate = useNavigate();

    const row = 'flex w-full items-center gap-3 rounded-xl p-3 text-left';

    return (
        <BottomSheet open={open} onClose={onClose} dismissible={dismissible}>
            <p className="mb-3 text-[15px] font-semibold" style={{ color: 'var(--tg-text)' }}>
                Manzilni tanlang
            </p>

            <div className="space-y-2">
                {addresses.map((a) => {
                    const { title, subtitle } = addressLines(a);
                    const active = mode === 'delivery' && a.id === currentId;
                    return (
                        <button
                            key={a.id}
                            onClick={() => {
                                haptic('light');
                                onPickAddress(a);
                            }}
                            className={row}
                            style={{ background: 'var(--tg-section-bg)' }}
                        >
                            <span aria-hidden>📍</span>
                            <span className="min-w-0 flex-1">
                                <span className="block text-[14px] font-semibold" style={{ color: 'var(--tg-text)' }}>{title}</span>
                                {subtitle && (
                                    <span className="block truncate text-[12px]" style={{ color: 'var(--tg-hint)' }}>{subtitle}</span>
                                )}
                            </span>
                            {active && <span style={{ color: 'var(--tg-link)' }}>✓</span>}
                        </button>
                    );
                })}

                <button
                    onClick={() => {
                        haptic('light');
                        onPickup();
                    }}
                    className={row}
                    style={{ background: 'var(--tg-section-bg)' }}
                >
                    <span aria-hidden>🛍</span>
                    <span className="flex-1 text-[14px] font-semibold" style={{ color: 'var(--tg-text)' }}>
                        Olib ketaman
                    </span>
                    {mode === 'pickup' && <span style={{ color: 'var(--tg-link)' }}>✓</span>}
                </button>

                <button
                    onClick={() => {
                        haptic('light');
                        navigate('/address/new');
                    }}
                    className={row}
                    style={{ background: 'var(--tg-section-bg)' }}
                >
                    <span aria-hidden>➕</span>
                    <span className="flex-1 text-[14px] font-semibold" style={{ color: 'var(--tg-link)' }}>
                        Yangi manzil
                    </span>
                </button>
            </div>
        </BottomSheet>
    );
}
