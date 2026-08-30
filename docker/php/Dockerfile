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
