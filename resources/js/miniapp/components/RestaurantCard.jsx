import { useNavigate } from 'react-router-dom';
import Thumb from './Thumb';
import { distanceLabel, etaLabel } from '../lib/format';
import { haptic } from '../lib/telegram';

export default function RestaurantCard({ restaurant }) {
    const navigate = useNavigate();
    const open = restaurant.is_open_now;

    const go = () => {
        if (!open) return;
        haptic('light');
        navigate(`/r/${restaurant.id}`, { state: { restaurant } });
    };

    return (
        <button
            onClick={go}
            disabled={!open}
            className="flex w-full items-center gap-3 rounded-2xl p-3 text-left transition active:scale-[0.99] disabled:cursor-default"
            style={{
                background: 'var(--tg-section-bg)',
                opacity: open ? 1 : 0.45,
            }}
        >
            <Thumb
                url={restaurant.logo_url}
                name={restaurant.name}
                rounded="rounded-xl"
                className="h-14 w-14 shrink-0"
            />

            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <span className="truncate text-[15px] font-semibold" style={{ color: 'var(--tg-text)' }}>
                        {restaurant.name}
                    </span>
                </div>

                <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[12px]" style={{ color: 'var(--tg-hint)' }}>
                    {restaurant.distance_km != null && <span>{distanceLabel(restaurant.distance_km)}</span>}
                    {open && (
                        <>
                            <span>·</span>
                            <span>{etaLabel(restaurant.distance_km, restaurant.avg_prep_time_min)}</span>
                        </>
                    )}
                </div>
            </div>

            <span
                className="shrink-0 rounded-full px-2 py-1 text-[11px] font-medium"
                style={
                    open
                        ? { background: 'rgba(52,199,89,0.15)', color: '#34c759' }
                        : { background: 'var(--tg-secondary-bg)', color: 'var(--tg-hint)' }
                }
            >
                {open ? 'Ochiq' : 'Yopiq'}
            </span>
        </button>
    );
}
