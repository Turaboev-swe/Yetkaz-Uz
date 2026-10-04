import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../lib/api';
import { haptic } from '../lib/telegram';

const AUTO_MS = 4000; // avtomatik almashish oralig'i
const PAUSE_MS = 8000; // foydalanuvchi surgandan keyin shuncha avtomatika to'xtaydi

function usePrefersReducedMotion() {
    const query = '(prefers-reduced-motion: reduce)';
    const [reduced, setReduced] = useState(() => window.matchMedia?.(query).matches ?? false);

    useEffect(() => {
        const mq = window.matchMedia?.(query);
        if (!mq) return;
        const onChange = () => setReduced(mq.matches);
        mq.addEventListener?.('change', onChange);
        return () => mq.removeEventListener?.('change', onChange);
    }, []);

    return reduced;
}

/**
 * Bosh sahifa reklama karuseli (2:1). Bannerlar o'zi yuklaydi; API xato bersa
 * yoki banner yo'q bo'lsa — hech narsa chizmaydi (ro'yxat odatdagidek ishlaydi).
 * Rasmi ochilmagan banner ham jimgina tashlab yuboriladi.
 */
export default function BannerCarousel() {
    const navigate = useNavigate();
    const reducedMotion = usePrefersReducedMotion();
    const [banners, setBanners] = useState([]);
    const [broken, setBroken] = useState(() => new Set());
    const [index, setIndex] = useState(0);
    const scroller = useRef(null);
    const pausedUntil = useRef(0);

    useEffect(() => {
        let alive = true;
        api.banners()
            .then((res) => alive && setBanners(res.data || []))
            .catch(() => alive && setBanners([]));
        return () => {
            alive = false;
        };
    }, []);

    const slides = banners.filter((b) => !broken.has(b.id));
    const count = slides.length;
    const current = Math.min(index, Math.max(count - 1, 0));

    const goTo = (i) => {
        const el = scroller.current;
        if (!el) return;
        el.scrollTo({ left: i * el.clientWidth, behavior: reducedMotion ? 'auto' : 'smooth' });
    };

    // Avtomatik almashish: 2+ banner, "reduced motion" o'chiq, sahifa ko'rinib
    // turgan va foydalanuvchi yaqinda surmagan bo'lsa.
    useEffect(() => {
        if (count < 2 || reducedMotion) return;
        const timer = setInterval(() => {
            if (document.hidden || Date.now() < pausedUntil.current) return;
            goTo((current + 1) % count);
        }, AUTO_MS);
        return () => clearInterval(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [count, current, reducedMotion]);

    if (count === 0) return null;

    const pause = () => {
        pausedUntil.current = Date.now() + PAUSE_MS;
    };

    const onScroll = (e) => {
        const el = e.currentTarget;
        if (el.clientWidth > 0) setIndex(Math.round(el.scrollLeft / el.clientWidth));
    };

    const open = (b) => {
        if (b.target_type !== 'restaurant' || !b.restaurant_id) return;
        haptic('light');
        navigate(`/r/${b.restaurant_id}`);
    };

    return (
        <section className="mt-2" aria-roledescription="carousel" aria-label="Reklama">
            <div
                ref={scroller}
                onScroll={onScroll}
                onTouchStart={pause}
                onPointerDown={pause}
                onWheel={pause}
                className="no-scrollbar flex snap-x snap-mandatory overflow-x-auto rounded-2xl"
                style={{ background: 'var(--tg-secondary-bg)' }}
            >
                {slides.map((b, i) => {
                    const clickable = b.target_type === 'restaurant' && b.restaurant_id;
                    const Slide = clickable ? 'button' : 'div';
                    return (
                        <Slide
                            key={b.id}
                            {...(clickable ? { type: 'button', onClick: () => open(b) } : {})}
                            className="block aspect-[2/1] w-full shrink-0 snap-center snap-always"
                            aria-label={`${i + 1} / ${count}`}
                        >
                            <img
                                src={b.image_url}
                                alt=""
                                loading="lazy"
                                decoding="async"
                                draggable={false}
                                onError={() => setBroken((s) => new Set(s).add(b.id))}
                                className="h-full w-full object-cover"
                            />
                        </Slide>
                    );
                })}
            </div>

            {count > 1 && (
                <div className="mt-2 flex justify-center gap-1.5">
                    {slides.map((b, i) => (
                        <button
                            key={b.id}
                            type="button"
                            aria-label={`${i + 1}-banner`}
                            aria-current={i === current}
                            onClick={() => {
                                pause();
                                goTo(i);
                            }}
                            className="h-1.5 rounded-full transition-all"
                            style={{
                                width: i === current ? 16 : 6,
                                background: i === current ? 'var(--tg-button)' : 'var(--tg-hint)',
                                opacity: i === current ? 1 : 0.45,
                            }}
                        />
                    ))}
                </div>
            )}
        </section>
    );
}
