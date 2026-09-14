import { useNavigate } from 'react-router-dom';
import BottomSheet from './BottomSheet';
import { resolvedAddress } from '../lib/address';
import { haptic } from '../lib/telegram';

/**
 * B: manzil tanlash. Yuqoridan pastga — saqlangan manzillar (joriysi
 * belgilangan) / ➕ Yangi manzil. Nomlar DOIM o'zbekcha (districts jadvalidan).
 *
 * Faqat manzil tanlaydi — yetkazish/olib ketish rejimi bu yerda so'ralmaydi
 * (keyingi qadam, DeliveryModeSheet).
 */
export default function AddressPickerSheet({ open, onClose, dismissible = true, addresses, currentId, onPickAddress }) {
    const navigate = useNavigate();

    const row = 'flex w-full items-center gap-3 rounded-xl p-3 text-left';

    return (
        <BottomSheet open={open} onClose={onClose} dismissible={dismissible}>
            <p className="mb-3 text-[15px] font-semibold" style={{ color: 'var(--tg-text)' }}>
                Manzilni tanlang
            </p>

            <div className="space-y-2">
                {addresses.map((a) => {
                    // Tanlashda LABEL ("Uy"/"Ish") foydali — pastida haqiqiy manzil.
                    const label = a.label || a.district || 'Manzil';
                    const detail = resolvedAddress(a);
                    const active = a.id === currentId;
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
                                <span className="block text-[14px] font-semibold" style={{ color: 'var(--tg-text)' }}>{label}</span>
                                {detail && detail !== label && (
                                    <span className="block truncate text-[12px]" style={{ color: 'var(--tg-hint)' }}>{detail}</span>
                                )}
                            </span>
                            {active && <span style={{ color: 'var(--tg-link)' }}>✓</span>}
                        </button>
                    );
                })}

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
