# Yetkaz

Telegram bot va Mini App orqali ovqat yetkazib berish platformasi. Foydalanuvchi
o'z shahridagi restoran va fast-foodlardan buyurtma qiladi; buyurtma avtomatik
oshxonaga tushadi, chek printerdan chiqadi, mijozga yetkazib berish vaqti
hisoblab yuboriladi.

To'liq spetsifikatsiya: [Claude.md](Claude.md).

## Stack

Laravel 12 · Octane (RoadRunner) · PostgreSQL 16 + PostGIS · Redis · Laravel Reverb
· Laravel Horizon · Nutgram (Telegram)

Hozirgi holat: **1-bosqich** — loyiha asosi, migratsiyalar/modellar, Docker muhiti
va oddiy `/start` handleri. Ro'yxatdan o'tish oqimi, Mini App, ETA hisoblash va
boshqalar keyingi bosqichlarda.

---

## Talablar

- Docker + Docker Compose
- Telegram bot tokeni ([@BotFather](https://t.me/BotFather) dan)

PHP / Composer / Node lokal mashinada shart emas — hammasi konteyner ichida ishlaydi.
Quyidagi buyruqlar `docker compose` bilan. Agar `make` o'rnatilgan bo'lsa
(`sudo apt install make`), qisqa variantlari ham bor — [Makefile](Makefile) ga qarang.

---

## O'rnatish

```bash
# 1. .env yarating
cp .env.example .env
```

```bash
# 2. .env ichida to'ldiring:
#    TELEGRAM_BOT_TOKEN=   <-- BotFather bergan token (tirnoqsiz, probelsiz)
#    kerak bo'lsa: UID/GID (id -u / id -g) va port raqamlari
```

```bash
# 3. Image'ni yig'ing
docker compose build

# 4. APP_KEY generatsiya qiling
docker compose run --rm app php artisan key:generate

# 5. Servislarni ko'taring
docker compose up -d

# 6. Migratsiyalar
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed        # namuna ma'lumot (ixtiyoriy)
```

`TELEGRAM_BOT_TOKEN` **hech qachon** git'ga tushmaydi — `.env` `.gitignore` da.

---

## Servislar va portlar

`docker compose up -d` quyidagilarni ko'taradi:

| Servis | Vazifa | Host manzil | Sozlash (`.env`) |
|---|---|---|---|
| `app` | Octane (RoadRunner) HTTP | http://localhost:8010 | `APP_FORWARD_PORT` |
| `bot` | Telegram bot — long polling | (tashqi port yo'q) | — |
| `horizon` | Navbat ishchilari (Redis) | (tashqi port yo'q) | — |
| `reverb` | WebSocket server | ws://localhost:8080 | `REVERB_FORWARD_PORT` |
| `scheduler` | `schedule:work` (cron) | (tashqi port yo'q) | — |
| `postgres` | PostgreSQL 16 + PostGIS | localhost:55432 | `DB_FORWARD_PORT` |
| `redis` | Kesh / navbat / sessiya | localhost:56379 | `REDIS_FORWARD_PORT` |
| `vite` | Mini App dev server (React) — `profiles: [dev]`, `docker compose up -d vite` | http://localhost:5174 | `VITE_FORWARD_PORT` |

Portlar boshqa loyihalar bilan to'qnashmasligi uchun nostandart tanlangan.

Holatni ko'rish: `docker compose ps`

> `docker-compose.yml` va `.env.example` dagi baza paroli (`DB_PASSWORD=secret`)
> **faqat lokal ishlab chiqish uchun**. Production serverda boshqa, kuchli parol
> ishlatiladi va u faqat server `.env` faylida turadi.

---

## Telegram bot

`bot` konteyneri `php artisan nutgram:run` ni long polling rejimida ishga tushiradi
va `docker compose up -d` bilan avtomatik ko'tariladi.

### ⚠️ MUHIM: bir vaqtda faqat bitta long polling

Telegram bitta bot uchun bir vaqtda **faqat bitta** `getUpdates` (long polling)
ulanishiga ruxsat beradi. Ikkinchi nusxa ishga tushsa Telegram
**`409 Conflict`** qaytaradi va ikkalasi ham to'g'ri ishlamaydi.

Shuning uchun:

- `bot` konteyneri ishlab turганда **qo'lda `php artisan nutgram:run` ISHLATMANG**
  (`docker compose exec app php artisan nutgram:run` ham mumkin emas).
- Bot handlerlariga ([routes/telegram.php](routes/telegram.php)) o'zgartirish
  kiritganingizda konteynerni qayta ishga tushiring:
  ```bash
  docker compose restart bot
  ```
- Production'da long polling emas, **webhook** ishlatiladi
  (`php artisan nutgram:hook:set <URL>`). U holda `bot` servisini o'chirib qo'ying
  yoki `docker compose up -d --scale bot=0`.

### Botni sinash

1. Telegram'da o'z botingizni oching (BotFather bergan `@username`).
2. `/start` yuboring.
3. Bot javob beradi: salomlashadi va sizning `telegram_id` ingizni qaytaradi.

---

## Mini App (Telegram Web App)

React + Vite. Laravel `/app` marshruti SPA'ni beradi, API `/api/*` xuddi shu
domenda (CORS shart emas).

**Ikki rejim** — bir vaqtda bittasi (`public/hot` fayli hal qiladi):

| Rejim | Qachon | Ishga tushirish |
|---|---|---|
| Dev server (HMR) | Kompyuter brauzerida ishlash | `docker compose up -d vite` |
| Build (statik) | Telegram / telefon orqali test | `docker compose stop vite && npm run build` |

`vite` konteyneri `npm install` ni o'zi bajaradi. Build faqat kompyuter
brauzeriga (`localhost:5174`) ishora qiladi — telefonda ko'rinmaydi, shuning
uchun Telegram testi uchun `npm run build` kerak.

### Kompyuter brauzerida sinash (Telegramsiz)

1. `docker compose up -d vite`
2. Imzolangan initData oling (bot tokeni chiqmaydi):

   ```bash
   docker compose exec app php artisan telegram:test-init-data <telegram_id>
   ```

3. Natijani `.env` ga `TELEGRAM_DEV_INIT_DATA=`, `docker compose up -d`.
4. http://localhost:8010/app — `<telegram_id>` botda ro'yxatdan o'tgan bo'lsa
   (telefon+ism+manzil) restoranlar ro'yxati chiqadi.

### Telegram ichida sinash

1. Public HTTPS tunnel (hisob shart emas, Docker orqali):

   ```bash
   docker run -d --name yetkaz-tunnel --network host --restart unless-stopped \
     cloudflare/cloudflared:latest tunnel --url http://localhost:8010 --no-autoupdate
   docker logs yetkaz-tunnel 2>&1 | grep -o 'https://[a-z-]*\.trycloudflare\.com'
   ```

2. `docker compose stop vite && npm run build`
3. `.env` ga: `TELEGRAM_MINI_APP_URL=https://<tunnel>.trycloudflare.com/app`,
   `docker compose restart bot`.
4. BotFather → menyu tugmasi URL'i: xuddi shu `https://<tunnel>.../app`
   (yoki `/empty` — `/start` dagi inline tugmalar baribir ishlaydi).
5. Telegram'da `/start` — restoranlar ro'yxati inline tugmalar bilan
   ("Buyurtma berish" dan oldin). Tugma Mini App'ni o'sha restoran menyusida ochadi.

> ⚠️ Bepul `trycloudflare.com` manzili tunnel qayta ishga tushganda o'zgaradi —
> `.env` va BotFather menyu tugmasini yangilash kerak. JS o'zgarsa: `npm run build`.
> Tunnel loglari: `docker logs -f yetkaz-tunnel`.

---

## Loglarni ko'rish

| Nima | Buyruq |
|---|---|
| Bot (long polling, kelgan `/start` lar) | `docker compose logs -f bot` |
| Mini App (Vite dev server) | `docker compose logs -f vite` |
| Octane / HTTP | `docker compose logs -f app` |
| Horizon (navbat) | `docker compose logs -f horizon` |
| Laravel log fayli | `docker compose exec app tail -f storage/logs/laravel.log` |
| Barcha servislar birga | `docker compose logs -f` |
| Horizon paneli (brauzer) | http://localhost:8010/horizon |

---

## Kod o'zgartirgandan keyin nima qilish kerak

Octane worker'lari PHP kodini xotirada saqlaydi, shuning uchun ko'p o'zgarishlar
avtomat ko'rinmaydi:

| O'zgarish | Kerakli amal |
|---|---|
| PHP kod (`app/`, `routes/web.php`) | `docker compose exec app php artisan octane:reload` (~1s) |
| Bot handlerlari (`routes/telegram.php`) | `docker compose restart bot` |
| Mini App (`resources/js/miniapp/`) — dev server | hech narsa — Vite HMR avtomat |
| Mini App (`resources/js/miniapp/`) — Telegram testi | `npm run build` |
| `config/*.php` | `docker compose restart app bot horizon scheduler` |
| `.env` | `docker compose up -d` (o'zgargan konteynerlar qayta yaratiladi) |
| Yangi migratsiya fayli | `docker compose exec app php artisan migrate` |
| `composer.json` (yangi paket) | `docker compose run --rm app composer install` → `docker compose up -d` |
| `Dockerfile` / `docker-compose.yml` | `docker compose build` → `docker compose up -d` |

---

## To'xtatish

```bash
docker compose stop      # konteynerlarni pauza qiladi
docker compose down      # konteynerlarni o'chiradi, ma'lumot (volume) saqlanadi
docker compose down -v   # + volume'lar ham o'chadi (baza va Redis tozalanadi)
```

---

## Testlar

```bash
docker compose exec app php artisan test
```

Testlar alohida `yetkaz_test` bazasida ishlaydi (postgres birinchi ko'tarilganda
`docker/postgres/init/` skripti yaratadi). Migratsiyalarda PostGIS / pg_trgm
ishlatilgani uchun testlar ham PostgreSQL'da bajariladi.

---

## Struktura (1-bosqich)

```
app/Models/            User, City, Address, Restaurant, Category,
                       Product, Order, OrderStatusHistory
database/migrations/   yuqoridagi jadvallar + PostGIS / pg_trgm kengaytmalari
database/seeders/      DemoSeeder — 1 shahar, 2 restoran, menyu
routes/telegram.php    Nutgram handlerlari (hozircha: /start)
docker/                php/Dockerfile (Octane + RoadRunner), postgres/init
docker-compose.yml     app, bot, horizon, reverb, scheduler, postgres, redis
```

Keyingi bosqichlar: [Claude.md](Claude.md#ishlab-chiqish-bosqichlari).
