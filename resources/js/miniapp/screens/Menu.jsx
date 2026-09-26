import { useEffect, useMemo, useState } from 'react';
import { useParams, useNavigate, useLocation } from 'react-router-dom';
import { api } from '../lib/api';
import { useAsync } from '../hooks/useAsync';
import { useScrollSpy } from '../hooks/useScrollSpy';
import { showBackButton, setMainButton, hideMainButton, isInsideTelegram } from '../lib/telegram';
import { useCart, cartQty, cartCount, cartTotal } from '../store/cart';
import { somLabel } from '../lib/format';
import CategoryTabs from '../components/CategoryTabs';
import DishCard from '../components/DishCard';
import DishDetailSheet from '../components/DishDetailSheet';
import CartBar from '../components/CartBar';
import { Spinner, ErrorState, EmptyState } from '../components/States';

export default function Menu() {
    const { id } = useParams();
    const rid = Number(id);
    const navigate = useNavigate();
    const location = useLocation();

    // Tafsilot oynasidagi taom (rasm/nom bosilganda).
    const [detail, setDetail] = useState(null);

    // Orqaga: tafsilot ochiq bo'lsa — faqat uni yopadi; aks holda menyudan
    // restoranlar ro'yxatiga (ilova yopilmaydi). Bir vaqtda bitta handler.
    useEffect(
        () => showBackButton(detail ? () => setDetail(null) : () => navigate('/')),
        [detail, navigate],
    );

    const preload = location.state?.restaurant;
    const info = useAsync(() => api.restaurant(rid), [rid]);
    const menu = useAsync(() => api.menu(rid), [rid]);

    const restaurant = info.data?.data || preload;
    const categories = useMemo(
        () => (menu.data?.data || []).filter((c) => (c.products || []).length > 0),
        [menu.data],
    );
    const ids = useMemo(() => categories.map((c) => c.id), [categories]);
    const { active, scrollTo } = useScrollSpy(ids);

    const carts = useCart((s) => s.carts);
    const add = useCart((s) => s.add);
    const remove = useCart((s) => s.remove);

    const count = cartCount(carts, rid);
    const total = cartTotal(carts, rid);

    // Telegram MainButton — savat bo'sh emasligida.
    useEffect(() => {
        if (count === 0) {
            hideMainButton();
            return;
        }
        return setMainButton({
            text: `Savat (${count}) — ${somLabel(total)}`,
            onClick: () => navigate(`/cart/${rid}`),
        });
    }, [count, total, rid, navigate]);

    if (menu.loading) return <Spinner />;
    if (menu.error) return <ErrorState error={menu.error} onRetry={menu.reload} />;

    return (
        <>
            <div className="mx-auto max-w-md px-4 pb-28 pt-2">
                <header className="pb-1">
                    <div className="flex items-center gap-2">
                        <h1 className="text-[18px] font-bold" style={{ color: 'var(--tg-text)' }}>
                            {restaurant?.name || 'Menyu'}
                        </h1>
                        {restaurant && !restaurant.is_open_now && (
                            <span
                                className="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                style={{ background: 'var(--tg-secondary-bg)', color: 'var(--tg-hint)' }}
                            >
                                Yopiq
                            </span>
                        )}
                    </div>
                    {restaurant?.min_order_amount > 0 && (
                        <p className="mt-0.5 text-[12px]" style={{ color: 'var(--tg-hint)' }}>
                            Minimal buyurtma: {somLabel(restaurant.min_order_amount)}
                        </p>
                    )}
                </header>

                {categories.length === 0 ? (
                    <EmptyState>Menyu hozircha bo‘sh.</EmptyState>
                ) : (
                    <>
                        <CategoryTabs categories={categories} activeId={active} onSelect={scrollTo} />

                        <div className="mt-2 space-y-7">
                            {categories.map((c) => (
                                <section key={c.id} id={`cat-${c.id}`} className="menu-section">
                                    <h2 className="mb-2 text-[15px] font-bold" style={{ color: 'var(--tg-text)' }}>
                                        {c.name}
                                    </h2>
                                    <div className="grid grid-cols-2 gap-2.5">
                                        {c.products.map((p) => (
                                            <DishCard
                                                key={p.id}
                                                product={p}
                                                qty={cartQty(carts, rid, p.id)}
                                                onAdd={() => add(rid, p)}
                                                onRemove={() => remove(rid, p.id)}
                                                onOpen={() => setDetail(p)}
                                            />
                                        ))}
                                    </div>
                                </section>
                            ))}
                        </div>
                    </>
                )}
            </div>

            <DishDetailSheet
                product={detail}
                qty={detail ? cartQty(carts, rid, detail.id) : 0}
                onAdd={() => add(rid, detail)}
                onRemove={() => remove(rid, detail.id)}
                onClose={() => setDetail(null)}
            />

            {/* Telegram'da savat vazifasini MainButton bajaradi — panel faqat brauzerda. */}
            {count > 0 && !isInsideTelegram() && (
                <CartBar count={count} total={total} onClick={() => navigate(`/cart/${rid}`)} />
            )}
        </>
    );
}
