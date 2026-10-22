import { useEffect, useMemo, useState } from 'react';

/**
 * Yetkaz logosi — restoran logosi ham bo'lmasa (yoki yuklanmasa) tarelkada shu ko'rinadi.
 * Fayllar public/images/ ichida: avval maxsus tarelka varianti, u bo'lmasa mavjud
 * yetkaz-logo.png. Ikkalasi ham bo'lmasa — logosiz bo'sh tarelka (zanjir oxiri).
 */
const YETKAZ_LOGOS = ['/images/yetkaz-plate-logo.png', '/images/yetkaz-logo.png'];

/**
 * Taom rasmi — bitta umumiy komponent (menyu kartasi, tafsilot oynasi, savat).
 *
 * Tanlash tartibi:
 *   a) photoUrl bor va yuklansa            -> oddiy rasm
 *   b) rasm yo'q yoki yuklanmasa           -> oq tarelka + o'rtasida restoran logosi
 *   c) restoran logosi yo'q yoki yuklanmasa -> tarelka + Yetkaz logosi
 *   d) hech biri yuklanmasa                -> logosiz tarelka
 * Har bosqich `onError` da KEYINGISIGA o'tadi (indeks faqat oshadi) — cheksiz sikl yo'q.
 *
 * Tarelka rasm tashqi fayl emas — CSS bilan chiziladi. O'lcham `className` orqali
 * beriladi va rasm bilan AYNAN bir xil (layout siljimasin):
 *   - tashqi shakl: oq (#fff); `shape="circle"` -> to'liq doira, `"square"` -> kvadrat
 *   - ichki tarelka: tashqi diametrning 70% i, #f7f7f7 fon, #e6e6e6 ingichka chiziq
 *   - logo: markazda, diametrning 43% i, object-contain, doira bo'lib kesilgan
 */
export default function ProductImage({
    photoUrl,
    restaurantLogoUrl,
    name = '',
    shape = 'circle',
    className = '',
}) {
    const [photoFailed, setPhotoFailed] = useState(false);
    const [logoIndex, setLogoIndex] = useState(0);

    const logos = useMemo(
        () => [restaurantLogoUrl, ...YETKAZ_LOGOS].filter(Boolean),
        [restaurantLogoUrl],
    );

    // Manba o'zgarsa (masalan restoran ma'lumoti keyin yuklandi) — boshidan sinaymiz.
    useEffect(() => setPhotoFailed(false), [photoUrl]);
    useEffect(() => setLogoIndex(0), [logos]);

    const rounded = shape === 'circle' ? 'rounded-full' : '';

    if (photoUrl && !photoFailed) {
        return (
            <img
                src={photoUrl}
                alt={name}
                loading="lazy"
                onError={() => setPhotoFailed(true)}
                className={`${rounded} object-cover ${className}`}
            />
        );
    }

    const logo = logos[logoIndex]; // oxirgi bosqichdan keyin undefined -> logosiz tarelka

    return (
        <div
            role="img"
            aria-label={name}
            className={`relative shrink-0 overflow-hidden ${rounded} ${className}`}
            style={{ background: '#fff' }}
        >
            <div
                className="absolute left-1/2 top-1/2 aspect-square w-[70%] -translate-x-1/2 -translate-y-1/2 rounded-full"
                style={{ background: '#f7f7f7', border: '1px solid #e6e6e6' }}
            />
            {logo && (
                <img
                    key={logo}
                    src={logo}
                    alt=""
                    loading="lazy"
                    onError={() => setLogoIndex((i) => i + 1)}
                    className="absolute left-1/2 top-1/2 aspect-square w-[43%] -translate-x-1/2 -translate-y-1/2 rounded-full bg-white object-contain"
                />
            )}
        </div>
    );
}
