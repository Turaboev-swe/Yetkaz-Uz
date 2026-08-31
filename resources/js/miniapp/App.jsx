import { useEffect } from 'react';
import { Routes, Route, Navigate, useNavigate, useLocation } from 'react-router-dom';
import { getStartRestaurantId } from './lib/telegram';
import RestaurantList from './screens/RestaurantList';
import Menu from './screens/Menu';

/** Bot inline tugmasi / deep link bilan ochilganda to'g'ridan-to'g'ri menyuga. */
function StartRedirect() {
    const navigate = useNavigate();
    const { pathname } = useLocation();

    useEffect(() => {
        const rid = getStartRestaurantId();
        if (rid && pathname === '/') navigate(`/r/${rid}`, { replace: true });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return null;
}

export default function App() {
    return (
        <>
            <StartRedirect />
            <Routes>
                <Route path="/" element={<RestaurantList />} />
                <Route path="/r/:id" element={<Menu />} />
                <Route path="*" element={<Navigate to="/" replace />} />
            </Routes>
        </>
    );
}
