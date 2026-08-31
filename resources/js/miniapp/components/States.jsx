export function Spinner() {
    return (
        <div className="flex justify-center py-16">
            <div
                className="h-7 w-7 animate-spin rounded-full border-2 border-white/20"
                style={{ borderTopColor: 'var(--tg-link)' }}
            />
        </div>
    );
}

export function ErrorState({ error, onRetry }) {
    return (
        <div className="px-6 py-16 text-center">
            <p className="text-[15px]" style={{ color: 'var(--tg-text)' }}>
                {error?.message || 'Nimadir xato ketdi.'}
            </p>
            {onRetry && (
                <button
                    onClick={onRetry}
                    className="mt-4 rounded-xl px-5 py-2 text-[14px] font-medium"
                    style={{ background: 'var(--tg-button)', color: 'var(--tg-button-text)' }}
                >
                    Qayta urinish
                </button>
            )}
        </div>
    );
}

export function EmptyState({ children }) {
    return (
        <div className="px-6 py-16 text-center text-[15px]" style={{ color: 'var(--tg-hint)' }}>
            {children}
        </div>
    );
}
