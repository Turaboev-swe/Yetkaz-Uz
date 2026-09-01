import { useEffect } from 'react';

/**
 * Pastdan ko'tariladigan panel. `onClose` — fon bosilганда yoki tutqich orqali.
 * `dismissible=false` bo'lsa fon bosish yopmaydi (majburiy tanlov).
 */
export default function BottomSheet({ open, onClose, dismissible = true, children }) {
    useEffect(() => {
        if (open) document.body.style.overflow = 'hidden';
        return () => {
            document.body.style.overflow = '';
        };
    }, [open]);

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-50 flex flex-col justify-end">
            <div
                className="absolute inset-0 bg-black/50"
                onClick={dismissible ? onClose : undefined}
            />
            <div
                className="relative max-h-[85vh] overflow-y-auto rounded-t-2xl px-4 pb-[calc(1rem+env(safe-area-inset-bottom))] pt-2"
                style={{ background: 'var(--tg-secondary-bg)' }}
            >
                <div className="mx-auto mb-3 h-1 w-10 rounded-full" style={{ background: 'var(--tg-hint)', opacity: 0.4 }} />
                {children}
            </div>
        </div>
    );
}
