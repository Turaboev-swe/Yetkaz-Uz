import { useEffect, useRef } from 'react';

/** Sticky gorizontal kategoriya tablari (scroll-spy bilan sinxron). */
export default function CategoryTabs({ categories, activeId, onSelect }) {
    const stripRef = useRef(null);
    const btnRefs = useRef({});

    // Faol tab ko'rinib tursin (gorizontal siljish).
    useEffect(() => {
        const btn = btnRefs.current[activeId];
        if (btn) btn.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'smooth' });
    }, [activeId]);

    return (
        <div
            ref={stripRef}
            className="no-scrollbar sticky top-0 z-20 -mx-4 flex gap-2 overflow-x-auto px-4 py-2"
            style={{ background: 'var(--tg-bg)' }}
        >
            {categories.map((c) => {
                const active = c.id === activeId;
                return (
                    <button
                        key={c.id}
                        ref={(el) => (btnRefs.current[c.id] = el)}
                        onClick={() => onSelect(c.id)}
                        className="shrink-0 whitespace-nowrap rounded-full px-3.5 py-1.5 text-[13px] font-semibold transition"
                        style={{
                            background: active ? 'var(--tg-button)' : 'var(--tg-section-bg)',
                            color: active ? 'var(--tg-button-text)' : 'var(--tg-text)',
                        }}
                    >
                        {c.name}
                    </button>
                );
            })}
        </div>
    );
}
