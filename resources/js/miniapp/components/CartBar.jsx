import { somLabel } from '../lib/format';
import { haptic } from '../lib/telegram';

/**
 * Doimiy pastki savat paneli. Savat bo'sh bo'lsa render qilinmaydi
 * (chaqiruvchi tekshiradi). Scroll / kategoriya almashtirishда yo'qolmaydi.
 */
export default function CartBar({ count, total, label = 'Savat', onClick }) {
    return (
        <div className="fixed inset-x-0 bottom-0 z-40 px-3 pb-[calc(0.6rem+env(safe-area-inset-bottom))] pt-2">
            <button
                onClick={() => {
                    haptic('light');
                    onClick();
                }}
                className="mx-auto flex w-full max-w-md items-center justify-between rounded-2xl px-4 py-3 text-[15px] font-semibold shadow-lg"
                style={{ background: 'var(--tg-button)', color: 'var(--tg-button-text)' }}
            >
                <span>
                    {label} ({count})
                </span>
                <span>{somLabel(total)}</span>
            </button>
        </div>
    );
}
