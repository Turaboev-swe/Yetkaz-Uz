/** Tuman bo'yicha filtr — gorizontal siljiydigan chiplar. */
export default function DistrictFilter({ districts, value, onChange }) {
    if (!districts?.length) return null;

    const chip = (active) => ({
        background: active ? 'var(--tg-button)' : 'var(--tg-section-bg)',
        color: active ? 'var(--tg-button-text)' : 'var(--tg-text)',
    });

    return (
        <div className="no-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4 py-1">
            <button
                onClick={() => onChange(null)}
                className="shrink-0 whitespace-nowrap rounded-full px-3.5 py-1.5 text-[13px] font-medium"
                style={chip(value == null)}
            >
                Barchasi
            </button>
            {districts.map((d) => (
                <button
                    key={d.id}
                    onClick={() => onChange(d.id)}
                    className="shrink-0 whitespace-nowrap rounded-full px-3.5 py-1.5 text-[13px] font-medium"
                    style={chip(value === d.id)}
                >
                    {d.name}
                </button>
            ))}
        </div>
    );
}
