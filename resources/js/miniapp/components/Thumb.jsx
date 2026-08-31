/**
 * Rasm yoki zaxira (bosh harf). Seeder'da rasm yo'q — Filament'dan rasm yuklash
 * keyingi bosqichda; shunda `url` to'ladi.
 */
export default function Thumb({ url, name, className = '', rounded = 'rounded-full' }) {
    if (url) {
        return (
            <img
                src={url}
                alt={name || ''}
                loading="lazy"
                className={`${rounded} object-cover ${className}`}
            />
        );
    }

    return (
        <div
            className={`${rounded} ${className} flex items-center justify-center text-lg font-bold`}
            style={{ background: 'var(--tg-secondary-bg)', color: 'var(--tg-hint)' }}
        >
            {(name || '?').trim().charAt(0).toUpperCase()}
        </div>
    );
}
