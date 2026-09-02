#!/bin/sh
# Konteyner ishga tushganda bir marta bajariladigan idempotent sozlash.
# `public/` va `storage/` bind-mount qilingani uchun bu qadamlarni build vaqtida
# emas, ishga tushishда qilish kerak (aks holda toza checkout'da panellar CSS/JS'siz
# ochiladi va taom rasmlari 404 bo'ladi).
set -e
cd /app

# Filament panel assetlari (public/css|js/filament/*) — gitignored.
if [ ! -d public/css/filament ] || [ ! -d public/js/filament ]; then
    php artisan filament:assets || true
fi

# public/storage -> storage/app/public symlink (yuklangan / seed rasmlar).
if [ ! -e public/storage ]; then
    php artisan storage:link || true
fi

# Seed taom rasmlarini public diskka ko'chirish (dump'da photo_url bor, fayl yo'q).
if [ -d database/seeders/assets/products ]; then
    mkdir -p storage/app/public/products
    for f in database/seeders/assets/products/*.jpg; do
        [ -f "$f" ] || continue
        dst="storage/app/public/products/$(basename "$f")"
        [ -f "$dst" ] || cp "$f" "$dst" || true
    done
fi

exec "$@"
