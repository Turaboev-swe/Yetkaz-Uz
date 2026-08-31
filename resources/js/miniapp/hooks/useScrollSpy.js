import { useEffect, useRef, useState, useCallback } from 'react';

const TAB_OFFSET = 92; // address bar + sticky tab bar balandligi

/**
 * Sahifa aylantirilganda faol kategoriyani aniqlaydi; tab bosilganda esa
 * bo'limga silliq siljiydi va spy'ni qisqa vaqt "qulflaydi".
 *
 * Bo'lim elementlari `id="cat-<categoryId>"` bo'lishi kerak.
 */
export function useScrollSpy(ids) {
    const [active, setActive] = useState(ids[0] ?? null);
    const locked = useRef(false);
    const key = ids.join(',');

    useEffect(() => {
        setActive((a) => (ids.includes(a) ? a : ids[0] ?? null));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key]);

    useEffect(() => {
        const onScroll = () => {
            if (locked.current || ids.length === 0) return;
            let current = ids[0];
            for (const id of ids) {
                const el = document.getElementById(`cat-${id}`);
                if (el && el.getBoundingClientRect().top - TAB_OFFSET <= 1) current = id;
            }
            setActive(current);
        };
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
        return () => window.removeEventListener('scroll', onScroll);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key]);

    const scrollTo = useCallback((id) => {
        const el = document.getElementById(`cat-${id}`);
        if (!el) return;
        locked.current = true;
        setActive(id);
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        window.setTimeout(() => {
            locked.current = false;
        }, 650);
    }, []);

    return { active, scrollTo };
}
