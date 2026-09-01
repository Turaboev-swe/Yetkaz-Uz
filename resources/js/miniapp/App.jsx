import { useEffect } from 'react';
import { Routes, Route, Navigate, useNavigate, useLocation } from 'react-router-dom';
import { getStartTarget, hasInitData } from './lib/telegram';
import RestaurantList from './screens/RestaurantList';
import Menu from './screens/Menu';
import NewAddress from './screens/NewAddress';
import Cart from './screens/Cart';
import Checkout from './screens/Checkout';
import OrderSuccess from './screens/OrderSuccess';

/** initData yo'q bo'lsa — aniq xabar (umumiy 401 sababni yashiradi). */
function NoTelegram() {
    return (
        <div className="mx-auto max-w-md px-6 py-20 text-center">
            <div className="mb-3 text-4xl">🔒</div>
            <h1 className="mb-2 text-[17px] font-bold" style={{ color: 'var(--tg-text)' }}>
                Bu ilovani Telegram orqali oching
            </h1>
            <p className="text-[14px]" style={{ color: 'var(--tg-hint)' }}>
                Ilova Telegram bot ichidagi «🍿 Buyurtma berish» tugmasi orqali ishlaydi.
            </p>
        </div>
    );
}

/**
 * Bot WebApp tugmasi / deep link bilan ochilganda mos ekranni ochadi:
 * `?r=ID` -> menyu, `?screen=restaurants` -> ro'yxat (default).
 */
function StartRedirect() {
    const navigate = useNavigate();
    const { pathname } = useLocation();

    useEffect(() => {
        if (pathname !== '/') return;
        const { restaurantId } = getStartTarget();
        if (restaurantId) navigate(`/r/${restaurantId}`, { replace: true });
        // screen === 'restaurants' -> ro'yxat allaqachon "/" da
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return null;
}

export default function App() {
    if (!hasInitData()) return <NoTelegram />;

    return (
        <>
            <StartRedirect />
            <Routes>
                <Route path="/" element={<RestaurantList />} />
                <Route path="/address/new" element={<NewAddress />} />
                <Route path="/r/:id" element={<Menu />} />
                <Route path="/cart/:rid" element={<Cart />} />
                <Route path="/checkout/:rid" element={<Checkout />} />
                <Route path="/order/:id" element={<OrderSuccess />} />
                <Route path="*" element={<Navigate to="/" replace />} />
            </Routes>
        </>
    );
}
