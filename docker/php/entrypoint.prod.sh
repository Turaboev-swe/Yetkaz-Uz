#!/bin/sh
# Production konteyner ishga tushish skripti (docker-compose.prod.yml).
#
# `.env` faqat host'dan `:ro` mount qilinadi — config:cache uni build vaqtida
# emas, shu yerda o'qiydi. Har konteyner (app, horizon, reverb, scheduler, bot)
# shu skript bilan ishga tushadi; rolga qarab qo'shimcha qadamlar.
set -e
cd /app

# --- Hamma rol uchun: .env asosidagi kesh ---
php artisan config:cache
php artisan event:cache
# route:cache YO'Q — routes/web.php da closure bor (Route::get('/', fn () => ...)).

# --- Faqat HTTP roli (app): panel assetlari + storage symlink + view kesh ---
if [ "${HTTP_ROLE:-0}" = "1" ]; then
    php artisan view:cache
    php artisan filament:assets
    [ -e public/storage ] || php artisan storage:link

    # Seed taom rasmlari (dump'da photo_url bor, fayl storage volume'ida bo'lmasligi mumkin).
    if [ -d database/seeders/assets/products ]; then
        mkdir -p storage/app/public/products
        for f in database/seeders/assets/products/*.jpg; do
            [ -f "$f" ] || continue
            dst="storage/app/public/products/$(basename "$f")"
            [ -f "$dst" ] || cp "$f" "$dst" || true
        done
    fi
fi

# --- Migratsiyalar — faqat bitta konteynerda (app: RUN_MIGRATIONS=1) ---
if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    php artisan migrate --force
fi

exec "$@"
