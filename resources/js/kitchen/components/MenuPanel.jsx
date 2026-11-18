import { useCallback, useEffect, useRef, useState } from 'react';
import { api } from '../lib/api';
import { buttonLabel, filterCategories, nextSoldOut, replaceProduct } from '../lib/menuStock';

// Menyu ochiq turganda qoldiq (restoran paneli, kunlik tiklanish, buyurtmalar) o'zgarsa planshet ham yangilansin.
const REFRESH_MS = 20000;

/**
 * Oshxona "Menyu" bo'limi: taom tugab qolganda BIR bosishda «Tugadi» (qoldiq = 0), qaytganda «Bor».
 * Tugma `is_available` ga tegmaydi — Mini App qoldiq 0 ni o'zi kulrang «Tugadi» qilib ko'rsatadi.
 */
export default function MenuPanel({ restaurants, onError }) {
    const [restaurantId, setRestaurantId] = useState(restaurants[0]?.id);
    const [categories, setCategories] = useState([]);
    const [loading, setLoading] = useState(true);
    const [busyId, setBusyId] = useState(null);
    const [query, setQuery] = useState('');
    const busy = useRef(null);
    busy.current = busyId;

    const load = useCallback(
        async (silent = false) => {
            if (restaurantId === undefined) return;
            try {
                const r = await api.menu(restaurantId);
                // Tugma bosilgan paytda kelgan eski javob yangi holatni bosib ketmasin.
                if (!silent || busy.current === null) setCategories(r.data || []);
            } catch (e) {
                if (!silent) onError(e.message);
            } finally {
                setLoading(false);
            }
        },
        [restaurantId, onError],
    );

    useEffect(() => {
        setLoading(true);
        load();
        const timer = setInterval(() => {
            if (!document.hidden) load(true);
        }, REFRESH_MS);
        return () => clearInterval(timer);
    }, [load]);

    const toggle = async (product) => {
        setBusyId(product.id);
        try {
            const r = await api.setSoldOut(product.id, nextSoldOut(product));
            setCategories((cur) => replaceProduct(cur, r.data));
        } catch (e) {
            onError(e.message);
            load(true);
        } finally {
            setBusyId(null);
        }
    };

    const shown = filterCategories(categories, query);

    return (
        <div>
            <div className="mb-4 flex flex-wrap items-center gap-3">
                {restaurants.length > 1 && (
                    <select
                        value={restaurantId}
                        onChange={(e) => setRestaurantId(Number(e.target.value))}
                        className="h-11 rounded-xl bg-gray-800 px-3 text-[15px] font-bold text-gray-100"
                    >
                        {restaurants.map((r) => (
                            <option key={r.id} value={r.id}>
                                {r.name}
                            </option>
                        ))}
                    </select>
                )}
                <input
                    type="search"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder="Taom qidirish…"
                    className="h-11 min-w-0 flex-1 rounded-xl bg-gray-800 px-4 text-[15px] text-gray-100 placeholder-gray-500 outline-none"
                />
            </div>

            {loading && <p className="p-8 text-center text-gray-500">Yuklanmoqda…</p>}
            {!loading && shown.length === 0 && (
                <p className="p-12 text-center text-[16px] text-gray-500">{query ? 'Hech narsa topilmadi' : 'Menyu bo‘sh'}</p>
            )}

            {shown.map((c) => (
                <section key={c.id} className="mb-6">
                    <h2 className="mb-2 text-[13px] font-bold uppercase tracking-wide text-gray-500">{c.name}</h2>
                    <ul className="divide-y divide-gray-800 overflow-hidden rounded-2xl bg-[#171a21]">
                        {c.products.map((p) => (
                            <li key={p.id} className="flex items-center justify-between gap-3 px-4 py-3">
                                <div className="min-w-0">
                                    <p className={`truncate text-[16px] font-semibold ${p.sold_out ? 'text-gray-500' : 'text-gray-100'}`}>{p.name}</p>
                                    {p.hidden && <p className="text-[12px] text-amber-500">Menyudan yashirilgan (restoran panelida)</p>}
                                </div>
                                <button
                                    onClick={() => toggle(p)}
                                    disabled={busyId === p.id}
                                    aria-pressed={p.sold_out}
                                    className="h-12 min-w-[120px] shrink-0 rounded-xl px-4 text-[15px] font-bold disabled:opacity-50"
                                    style={
                                        p.sold_out
                                            ? { background: '#3f1d1d', color: '#fca5a5' }
                                            : { background: '#14342a', color: '#6ee7b7' }
                                    }
                                >
                                    {busyId === p.id ? '…' : buttonLabel(p)}
                                </button>
                            </li>
                        ))}
                    </ul>
                </section>
            ))}
        </div>
    );
}
