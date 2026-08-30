# Yetkaz

Telegram bot + Mini App orqali ovqat yetkazib berish platformasi.
To'liq spetsifikatsiya: [Claude.md](Claude.md).

## Stack

Laravel 12 · Octane (RoadRunner) · PostgreSQL 16 + PostGIS · Redis · Reverb · Horizon · Nutgram

## Ishga tushirish (Docker)

```bash
cp .env.example .env          # kerak bo'lsa UID/GID va portlarni moslang
make build                    # yoki: docker compose build
make up                       # postgres, redis, app(octane), horizon, reverb, scheduler
docker compose run --rm app php artisan key:generate
make migrate                  # yoki: make fresh  (fresh + demo seed)
make seed
```

Servislar:

| Servis | Host manzil | Izoh |
|---|---|---|
| App (Octane) | http://localhost:8010 | `APP_FORWARD_PORT` |
| Reverb (WS) | ws://localhost:8080 | `REVERB_FORWARD_PORT` |
| PostgreSQL | localhost:55432 | `DB_FORWARD_PORT` |
| Redis | localhost:56379 | `REDIS_FORWARD_PORT` |
| Vite (dev profili) | http://localhost:5174 | `make up-dev` |

Portlar boshqa loyihalar bilan to'qnashmasligi uchun nostandart; `.env` orqali o'zgartiriladi.

## Testlar

```bash
make test          # docker compose exec app php artisan test
```

Testlar alohida `yetkaz_test` bazasida ishlaydi (postgres konteyneri birinchi ko'tarilganda
`docker/postgres/init/` skripti yaratadi). Migratsiyalarda PostGIS/pg_trgm ishlatilgani uchun
testlar ham PostgreSQL'da bajariladi, SQLite emas.

## Struktura (1-bosqich)

```
database/migrations/   users, cities, addresses, restaurants, categories,
                       products, orders, order_status_history + PostGIS/pg_trgm
database/factories/    barcha modellar uchun
database/seeders/      DemoSeeder — 1 shahar, 2 restoran, menyu
app/Models/            User, City, Address, Restaurant, Category, Product,
                       Order, OrderStatusHistory
docker/                php/Dockerfile (Octane+RoadRunner), postgres/init
```

Keyingi bosqichlar: [Claude.md](Claude.md#ishlab-chiqish-bosqichlari).
# Yetkaz-Uz
