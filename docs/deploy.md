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
```

RAM/worker: `OCTANE_WORKERS` (default 3) `.env` yoki shell env orqali.

Horizon dashboard (`/horizon`) prod'da 403 — `HorizonServiceProvider::gate()`
faqat `local` da ochiq. Ko'rish uchun: gate'ga email qo'shing yoki SSH tunnel
(`ssh -L 8088:localhost:80 root@189.74.98.7` + nginx orqali... aslida gate'ni
sozlash osonroq).

## 6. Backup va tiklash (PROD-7)

Kunlik dump: `docker/prod/backup.sh` → `/opt/yetkaz/backups/yetkaz-YYYY-MM-DD.sql.gz`
(`gzip -9`, `--no-owner --no-privileges`). Retention **14 kun**. `/opt` diski
≥85% bo'lsa skript ogohlantiradi.

**O'rnatish (bir marta):**

```sh
sudo cp /opt/yetkaz/docker/prod/yetkaz-backup.cron /etc/cron.d/yetkaz-backup
sudo chmod 644 /etc/cron.d/yetkaz-backup
bash /opt/yetkaz/docker/prod/backup.sh          # sinov — bitta dump yaratadi
ls -la /opt/yetkaz/backups/
```

Cron har kuni **03:00 Asia/Tashkent** da ishlaydi (`CRON_TZ`), log
`/var/log/yetkaz-backup.log`.

> Off-site nusxa hozircha yo'q — disk nosozligida serverdagi backup ham yo'qoladi.
> Keyingi qadam: `backups/` ni S3 / boshqa serverga `rclone` yoki `scp` bilan
> ko'chirish (alohida cron).

**Tiklash (disaster recovery):**

```sh
cd /opt/yetkaz
FILE=backups/yetkaz-2026-09-03.sql.gz

# 1. Faqat baza konteyneri
docker compose -f docker-compose.prod.yml up -d postgres

# 2. Toza tiklash uchun bazani qayta yaratish (ixtiyoriy, lekin tavsiya etiladi)
docker compose -f docker-compose.prod.yml exec -T postgres \
  psql -U yetkaz -d postgres -c "DROP DATABASE IF EXISTS yetkaz; CREATE DATABASE yetkaz OWNER yetkaz;"
docker compose -f docker-compose.prod.yml exec -T postgres \
  psql -U yetkaz -d yetkaz -c "CREATE EXTENSION IF NOT EXISTS postgis; CREATE EXTENSION IF NOT EXISTS pg_trgm; CREATE EXTENSION IF NOT EXISTS unaccent;"

# 3. Dump'ni yuklash
gunzip -c "$FILE" | docker compose -f docker-compose.prod.yml exec -T postgres psql -U yetkaz -d yetkaz

# 4. Butun stack
docker compose -f docker-compose.prod.yml up -d
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force   # ehtiyot uchun
```

## 7. Domen + SSL + webhook

`yetqaz.uz` (+ `www`) 189.74.98.7 ga yo'naltirilgan bo'lishi shart.

```sh
cd /opt/yetkaz

# 1. DNS
dig +short yetqaz.uz        # 189.74.98.7 bo'lishi kerak

# 2. Yangi kod (certbot / prod-ssl.conf)
git pull
docker compose -f docker-compose.prod.yml up -d           # nginx prod.conf (HTTP+ACME) bilan

# 3. Sertifikat (Let's Encrypt, webroot)
bash docker/prod/init-cert.sh
#   expiry ogohlantirish uchun:  CERT_EMAIL=you@example.com bash docker/prod/init-cert.sh

# 4. .env — nano bilan (qiymatlarni terminalga chiqarmang):
#     APP_URL=https://yetqaz.uz
#     OCTANE_HTTPS=true
#     SESSION_SECURE_COOKIE=true
#     NGINX_CONF=prod-ssl.conf
#     TELEGRAM_MINI_APP_URL=https://yetqaz.uz/app
#     TELEGRAM_WEBHOOK_SECRET=$(openssl rand -hex 32)     # agar bo'sh bo'lsa
#     FILESYSTEM_PUBLIC_URL=https://yetqaz.uz/storage

# 5. SSL konfiguratsiya bilan qayta ishga tushirish
docker compose -f docker-compose.prod.yml up -d --build
curl -I https://yetqaz.uz/up                              # HTTP/2 200

# 6. Webhook o'rnatish
docker compose -f docker-compose.prod.yml exec app php artisan telegram:webhook:set

# 7. Bot polling konteynerini o'chirish (update lar endi webhook -> nginx -> app)
docker compose -f docker-compose.prod.yml stop bot
docker compose -f docker-compose.prod.yml rm -f bot

# 8. Sertifikat renewal cron
sudo cp docker/prod/yetkaz-cert-renew.cron /etc/cron.d/yetkaz-cert-renew
sudo chmod 644 /etc/cron.d/yetkaz-cert-renew
```

**Tekshiruv:**

```sh
curl -sI https://yetqaz.uz/up | head -1
openssl s_client -connect yetqaz.uz:443 -servername yetqaz.uz </dev/null 2>/dev/null | openssl x509 -noout -dates -issuer
docker compose -f docker-compose.prod.yml exec app php artisan tinker --execute \
  '$i = app(\SergiX44\Nutgram\Nutgram::class)->getWebhookInfo(); \
   echo $i->url, " pending=", $i->pending_update_count, " err=", ($i->last_error_message ?: "-");'
```

Telegram'da botga `/start` → "Ochish" tugmasi `https://yetqaz.uz/app` ni ochsin.

**Qaytish (webhook ishlamay qolsa):**

```sh
docker compose -f docker-compose.prod.yml exec app php artisan telegram:webhook:delete
# .env: NGINX_CONF=prod.conf (ixtiyoriy — SSL qolsa ham bo'ladi), bot xizmatini qayta:
docker compose -f docker-compose.prod.yml up -d bot
```

## 8. Reverb frontend (real-time — /kitchen, Mini App)

`VITE_REVERB_*` **build vaqtida** kerak: Vite `import.meta.env.VITE_*` ni
`npm run build` paytida kodga qattiq bog'laydi. Runtime `.env` ni frontend
ko'rmaydi. `docker-compose.prod.yml` bularni `build.args` orqali host `.env` dan
uzatadi, Dockerfile `frontend` bosqichi `ARG` + `ENV` bilan qabul qiladi.

**`.env` ga qo'shing** (nano bilan):

```ini
VITE_REVERB_APP_KEY=<REVERB_APP_KEY bilan AYNAN bir xil>
VITE_REVERB_HOST=yetqaz.uz
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
VITE_REVERB_PATH=/reverb
```

`echo.js` `wsPath=/reverb` bilan `wss://yetqaz.uz:443/reverb/app/<key>` ga ulanadi;
nginx (`prod-ssl.conf` **va** `prod.conf`) `location /reverb/` ni `reverb:8080` ga
`Upgrade`/`Connection` sarlavhalari bilan proxy qiladi — tayyor.

**Qayta build + deploy:**

```sh
cd /opt/yetkaz
git pull
docker compose -f docker-compose.prod.yml build app
docker compose -f docker-compose.prod.yml up -d
docker image prune -f
```

> `ARG` (→ `ENV`) qiymati o'zgarsa Docker `frontend` bosqichini o'sha nuqtadan
> avtomat qayta quradi (`npm run build` ham) — odatda `--no-cache` shart emas.
> Agar assetlar eski qolsa: `docker compose -f docker-compose.prod.yml build
> --no-cache app`. `x-app` anchor'i `horizon/reverb/...` bilan bir xil
> `yetkaz-app:prod` image'ni bo'lishadi — bitta `app` build yetarli.

**Tekshiruv:**

1. Brauzer: `https://yetqaz.uz/kitchen` → DevTools Console'da Echo/pusher xatosi
   yo'q. Network → WS: `wss://yetqaz.uz/reverb/app/...` `101 Switching Protocols`.
2. `/kitchen` sarlavhasidagi indikator **yashil** ("ulangan").
3. Build'ga kalit kirganini tasdiqlash:
   ```sh
   docker compose -f docker-compose.prod.yml exec app \
     sh -c 'grep -rl "yetqaz.uz" public/build/assets | head -1'
   ```
4. Real buyurtma (pastda) — kartochka **darhol** (15s polling emas) + ovozli signal.

**Real buyurtma bilan sinash:**

- Telegram bot orqali `/kitchen` restorani menyusidan buyurtma ber (Mini App →
  savat → buyurtma tasdiqlash), yoki:
- `docker compose -f docker-compose.prod.yml exec app php artisan tinker` ichida
  o'sha restoran uchun test buyurtma yaratib `OrderPlaced` event'ini broadcast qil.
- `/kitchen` sahifasi ochiq turganda kartochka WebSocket orqali < 1s ichida
  paydo bo'lishi va chime chalinishi kerak. 15s kutilsa — polling ishlayapti,
  ya'ni WebSocket ulanmagan (indikator qizil), build ARG yoki nginx'ni tekshir.

## 9. Keyingi qadamlar

- **Off-site backup** — `backups/` ni S3 / boshqa serverga `rclone`/`scp` (alohida cron).
