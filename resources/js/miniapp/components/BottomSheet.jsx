import { useEffect, useRef, useState } from 'react';

/** Shu masofadan (px) ko'proq pastga surilsa panel yopiladi. */
const SWIPE_CLOSE_PX = 90;

/**
 * Pastdan ko'tariladigan panel. `onClose` — fon bosilганда yoki tutqich orqali.
 * `dismissible=false` bo'lsa fon bosish yopmaydi (majburiy tanlov).
 * `swipeToClose` — panelni pastga surib yopish (faqat ichki scroll tepada bo'lsa,
 * aks holda oddiy scroll bilan to'qnashadi).
 */
export default function BottomSheet({ open, onClose, dismissible = true, swipeToClose = false, children }) {
    const panelRef = useRef(null);
    const startY = useRef(null);
    const [dragY, setDragY] = useState(0);

    useEffect(() => {
        if (open) document.body.style.overflow = 'hidden';
        return () => {
            document.body.style.overflow = '';
        };
    }, [open]);

    useEffect(() => {
        if (!open) setDragY(0);
    }, [open]);

    if (!open) return null;

    const swipe = swipeToClose
        ? {
              onTouchStart: (e) => {
                  startY.current = panelRef.current?.scrollTop === 0 ? e.touches[0].clientY : null;
              },
              onTouchMove: (e) => {
                  if (startY.current === null) return;
                  setDragY(Math.max(0, e.touches[0].clientY - startY.current));
              },
              onTouchEnd: () => {
                  if (startY.current === null) return;
                  startY.current = null;
                  if (dragY > SWIPE_CLOSE_PX) onClose?.();
                  else setDragY(0);
              },
          }
        : {};

    return (
        <div className="fixed inset-0 z-50 flex flex-col justify-end">
            <div
                className="absolute inset-0 bg-black/50"
                onClick={dismissible ? onClose : undefined}
            />
            <div
                ref={panelRef}
                {...swipe}
                className="relative max-h-[85vh] overflow-y-auto rounded-t-2xl px-4 pb-[calc(1rem+env(safe-area-inset-bottom))] pt-2"
                style={{
                    background: 'var(--tg-secondary-bg)',
                    transform: dragY ? `translateY(${dragY}px)` : undefined,
                    transition: startY.current === null ? 'transform 0.2s ease-out' : 'none',
                }}
            >
                <div className="mx-auto mb-3 h-1 w-10 rounded-full" style={{ background: 'var(--tg-hint)', opacity: 0.4 }} />
                {children}
            </div>
        </div>
    );
}
