# Yetkaz — production deploy (docker-compose.prod.yml)

Server: Ubuntu 24.04, 4 GB RAM, `/opt/yetkaz`. Docker Engine + Compose plugin.

`docker-compose.prod.yml` — dev `docker-compose.yml` dan farqlari:

| | dev | prod |
|---|---|---|
| Kod | bind mount `./:/app` | image ichida (`target: prod`) |
| Frontend | `vite` servisi (dev server) | build vaqtida `npm run build` → `public/build` |
| `.env` | bind mount, to'liq | host'dan faqat `:ro` (`config:cache` o'qiydi) |
| Portlar | `5432`, `6379`, `8010`, `8080` host'ga ochiq | faqat `nginx :80` |
| Restart | qisman | hamma xizmatda `unless-stopped` |
| Octane | auto worker | `--workers=3 --max-requests=500` |
| composer | dev bilan | `--no-dev` |

Xizmatlar: `nginx` (:80) → `app` (Octane), `horizon`, `reverb`, `scheduler`,
`bot` (long polling), `postgres`, `redis`.

---

## 1. Bir martalik server tayyorgarligi

```sh
# 2 GB swap (4 GB RAM uchun zaxira)
sudo bash /opt/yetkaz/docker/prod/setup-swap.sh
```

## 2. `.env`

```sh
cd /opt/yetkaz
cp .env.example .env
nano .env
```

**To'ldirilishi shart** (bo'sh yoki dev qiymat bilan):

| Kalit | Qiymat |
|---|---|
| `APP_KEY` | `php artisan key:generate --show` yoki tayyor: `base64:RswANkQv6udUv5xRrJasVUVzkVrb0Km6ZVGB795vrdE=` |
| `TELEGRAM_BOT_TOKEN` | @BotFather (rotatsiyadan keyingi yangi token) |
| `DB_PASSWORD` | kuchli tasodifiy (`openssl rand -base64 24`) |
| `REVERB_APP_SECRET` | kuchli tasodifiy |
| `REVERB_APP_KEY` | tasodifiy (frontend ham ko'radi) |
| `PRINT_AGENT_TOKEN` | kuchli tasodifiy |

**Production qiymatlari** (dev'dan o'zgartiring):

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=http://189.74.98.7          # domen tasdiqlangach https://<domen>
LOG_LEVEL=warning
LOG_STACK=stderr,daily

SESSION_ENCRYPT=true
# SESSION_SECURE_COOKIE=true         # FAQAT HTTPS bo'lgach
SESSION_SAME_SITE=lax

OCTANE_HTTPS=false                   # SSL bo'lgach true

DB_HOST=postgres
REDIS_HOST=redis
REVERB_HOST=reverb

TELEGRAM_MINI_APP_URL=              # BO'SH — domen + SSL tasdiqlangach
TELEGRAM_LOG_CHANNEL=stack

FILESYSTEM_PUBLIC_URL=/storage       # SSL bo'lgach https://<domen>/storage
```

> `TELEGRAM_MINI_APP_URL` bo'sh bo'lsa bot Mini App tugmalarini ko'rsatmaydi —
> bu **atayin**: Telegram Mini App faqat HTTPS bilan ishlaydi.

## 3. Birinchi build + ishga tushirish

```sh
cd /opt/yetkaz
docker compose -f docker-compose.prod.yml build      # ~3-5 daq (frontend + composer)
docker compose -f docker-compose.prod.yml up -d
docker compose -f docker-compose.prod.yml ps          # hammasi healthy/up
```

Migratsiyalar `app` konteyneri entrypoint'ida avtomat (`RUN_MIGRATIONS=1`).
Seed kerak bo'lsa qo'lda:

```sh
docker compose -f docker-compose.prod.yml exec app php artisan db:seed --force
```

Tekshirish:

```sh
curl -i http://189.74.98.7/up          # 200 OK
```

## 4. Yangilanish (keyingi deploylar)

```sh
cd /opt/yetkaz
git pull
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml up -d       # o'zgargan xizmatlar qayta yaratiladi
docker image prune -f
```

Migratsiyalar `app` qayta ishga tushganda avtomat bajariladi.

## 5. Operatsiyalar

```sh
make prod-logs                 # yoki: docker compose -f docker-compose.prod.yml logs -f
make prod-ps
docker compose -f docker-compose.prod.yml exec app php artisan horizon:status
docker compose -f docker-compose.prod.yml exec postgres pg_dump -U yetkaz yetkaz | gzip > backup-$(date +%F).sql.gz
```

RAM/worker: `OCTANE_WORKERS` (default 3) `.env` yoki shell env orqali.

## 6. Keyingi bosqichlar (bu deploydan tashqarida)

- **SSL / domen** (PROD-2 bilan bog'liq): domen → `docker/nginx/prod.conf` ga
  `443 ssl` bloki + certbot yoki Caddy. Keyin `X-Forwarded-Proto=https` avtomat,
  `OCTANE_HTTPS=true`, `SESSION_SECURE_COOKIE=true`, `TELEGRAM_MINI_APP_URL`,
  `FILESYSTEM_PUBLIC_URL` to'liq domen bilan.
- **Bot webhook** (PROD-2/PROD-6) — ⚠️ **muhim**: O'zbekiston serverida (eskiz.uz)
  xalqaro tarmoq beqaror. `getUpdates` long-poll tez-tez `cURL error 28` bilan
  uziladi → bot konteyneri qayta ishga tushadi (`restart: unless-stopped` bilan
  o'ziga keladi). Vaqtinchalik yumshatish qo'llangan: `--pollingTimeout=5` +
  Guzzle `ConnectException` retry (2×, `RedactingBotClientHandler`). **Doimiy
  yechim** — SSL bo'lgach webhook: `bot` konteynerini olib tashlab,
  `nutgram:hook:set` + `TELEGRAM_WEBHOOK_SECRET`. Webhook'da Telegram bizga
  qisqa so'rov yuboradi (uzoq ushlanadigan ulanish yo'q) — beqaror tarmoqqa
  ancha chidamli.
- **Reverb frontend**: `resources/js/*/lib/echo.js` da `wsPath: '/reverb'` +
  `VITE_REVERB_*` prod qiymatlari (nginx `/reverb/` locationи tayyor).
- **Postgres backup** (PROD-7): cron + off-site.
