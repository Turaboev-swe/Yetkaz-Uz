import { useState, useMemo, useEffect } from 'react';
import { api } from '../lib/api';
import { useAsync } from '../hooks/useAsync';
import { hideBackButton } from '../lib/telegram';
import AddressBar from '../components/AddressBar';
import DistrictFilter from '../components/DistrictFilter';
import RestaurantCard from '../components/RestaurantCard';
import { Spinner, ErrorState, EmptyState } from '../components/States';

export default function RestaurantList() {
    useEffect(hideBackButton, []);

    const base = useAsync(() => Promise.all([api.me(), api.districts()]), []);

    if (base.loading) return <Spinner />;
    if (base.error) return <ErrorState error={base.error} onRetry={base.reload} />;

    const [me, districtsRes] = base.data;
    const addresses = me.data?.addresses || [];
    const address = addresses.find((a) => a.is_default) || addresses[0];
    const districts = districtsRes.data || [];

    if (!address) {
        return <EmptyState>Avval botda manzilingizni qo‘shing.</EmptyState>;
    }

    return <Results address={address} districts={districts} />;
}

function Results({ address, districts }) {
    const [districtId, setDistrictId] = useState(null);

    const list = useAsync(
        () =>
            api.restaurants({
                address_id: address.id,
                include_closed: 1,
                district_id: districtId ?? undefined,
            }),
        [address.id, districtId],
    );

    const { open, closed } = useMemo(() => {
        const rows = list.data?.data || [];
        return {
            open: rows.filter((r) => r.is_open_now),
            closed: rows.filter((r) => !r.is_open_now),
        };
    }, [list.data]);

    return (
        <div className="mx-auto max-w-md px-4 pb-10 pt-2">
            <AddressBar address={address} />

            <div className="sticky top-0 z-10 -mx-4 px-4 pb-2 pt-1" style={{ background: 'var(--tg-bg)' }}>
                <DistrictFilter districts={districts} value={districtId} onChange={setDistrictId} />
            </div>

            {list.loading && <Spinner />}
            {list.error && <ErrorState error={list.error} onRetry={list.reload} />}

            {!list.loading && !list.error && (
                <>
                    {open.length === 0 && closed.length === 0 && (
                        <EmptyState>Bu hududda yetkazadigan restoran topilmadi.</EmptyState>
                    )}

                    <div className="mt-2 space-y-2">
                        {open.map((r) => (
                            <RestaurantCard key={r.id} restaurant={r} />
                        ))}
                    </div>

                    {closed.length > 0 && (
                        <>
                            <p className="mb-2 mt-6 text-[12px] font-medium uppercase tracking-wide" style={{ color: 'var(--tg-hint)' }}>
                                Hozir yopiq
                            </p>
                            <div className="space-y-2">
                                {closed.map((r) => (
                                    <RestaurantCard key={r.id} restaurant={r} />
                                ))}
                            </div>
                        </>
                    )}
                </>
            )}
        </div>
    );
}
