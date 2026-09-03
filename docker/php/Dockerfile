# syntax=docker/dockerfile:1

# RoadRunner binari — rasmiy image'dan olamiz (GitHub API rate-limit'idan qochish uchun).
FROM ghcr.io/roadrunner-server/roadrunner:2025.1.15 AS roadrunner

FROM php:8.4-cli-bookworm AS base

# --- Tizim kutubxonalari va PHP kengaytmalari (eng barqaror qatlam — keshda qoladi) ---
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libzip-dev libicu-dev libonig-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql pgsql bcmath intl sockets pcntl opcache zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-yetkaz.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=roadrunner /usr/bin/rr /usr/local/bin/rr

# Host UID/GID bilan mos foydalanuvchi (bind-mount fayllar egaligini to'g'ri saqlash uchun)
ARG UID=1000
ARG GID=1000
RUN { groupadd -g "${GID}" app || true; } \
    && { useradd -u "${UID}" -g "${GID}" -m -s /bin/bash app || true; }

WORKDIR /app

# --- Composer bog'liqliklari (kesh qatlami) ---
# Production build uchun: --build-arg COMPOSER_FLAGS="--no-dev"
ARG COMPOSER_FLAGS=""
COPY --chown=app:app composer.json composer.lock ./
RUN composer install ${COMPOSER_FLAGS} --no-scripts --no-autoloader --prefer-dist --no-interaction

# --- Ilova kodi ---
COPY --chown=app:app . .
RUN composer dump-autoload --optimize ${COMPOSER_FLAGS} \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R app:app /app

USER app

EXPOSE 8000
EXPOSE 8080

# Standart: Octane (RoadRunner) HTTP serveri
CMD ["php", "artisan", "octane:start", "--server=roadrunner", "--host=0.0.0.0", "--port=8000"]

# ==========================================================================
# Frontend (Vite / React) — FAQAT `prod` target uchun quriladi.
# Dev build (`docker compose build`, target: base) bu bosqichlarga tegmaydi.
# ==========================================================================
FROM node:22-bookworm-slim AS frontend
WORKDIR /app

# --- Vite build-vaqti o'zgaruvchilari ---
# Bular RUNTIME .env dan EMAS, Docker build ARG orqali keladi: Vite `import.meta.env`
# ni kompilyatsiya paytida qattiq bog'laydi. docker-compose.prod.yml build.args
# orqali host .env dan uzatiladi. Bo'sh bo'lsa echo.js real-time'ni o'chiradi
# (ilova polling bilan ishlayveradi).
ARG VITE_REVERB_APP_KEY=""
ARG VITE_REVERB_HOST=""
ARG VITE_REVERB_PORT=""
ARG VITE_REVERB_SCHEME=""
ARG VITE_REVERB_PATH=""
ENV VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY} \
    VITE_REVERB_HOST=${VITE_REVERB_HOST} \
    VITE_REVERB_PORT=${VITE_REVERB_PORT} \
    VITE_REVERB_SCHEME=${VITE_REVERB_SCHEME} \
    VITE_REVERB_PATH=${VITE_REVERB_PATH}

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js tailwind.config.js postcss.config.js ./
COPY resources ./resources
RUN npm run build

# --- Production image: base + qurilgan Mini App / kitchen assetlari ---
FROM base AS prod
COPY --from=frontend --chown=app:app /app/public/build ./public/build
