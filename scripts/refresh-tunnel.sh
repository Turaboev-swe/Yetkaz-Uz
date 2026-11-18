#!/usr/bin/env bash
#
# Lokal Mini App tunnelini BIR buyruq bilan yangilaydi (Telegram'da ERR_NAME_NOT_RESOLVED bo'lganda).
#
# Nima uchun kerak: bepul trycloudflare.com manzili tunnel har qayta ishga tushganda o'zgaradi — kompyuter reboot
# bo'lsa Docker `yetkaz-tunnel` ni o'zi ko'taradi, lekin YANGI manzil bilan; .env va Telegram esa eskisida qoladi.
#
# Ishlatish:
#   ./scripts/refresh-tunnel.sh              joriy tunnel tirik bo'lsa manzilini oladi, o'lik bo'lsa qayta yaratadi
#   ./scripts/refresh-tunnel.sh --recreate   tunnelni majburan qayta yaratadi (har doim yangi manzil)
#   make tunnel-refresh
#
# Qadamlar: tunnel manzili -> .env (faqat TELEGRAM_MINI_APP_URL) -> tekshiruv (/app, JS, API) -> config:clear +
# octane:reload -> bot/horizon/scheduler restart -> Telegram menyu tugmasi (setChatMenuButton) -> natija.
#
# Token faqat .env dan o'qiladi va HECH QAYERDA chop etilmaydi (curl argumentlariga ham tushmaydi: `-K -`).
# Production'ga tegmaydi: faqat lokal docker compose va lokal test bot.
#
set -euo pipefail
cd "$(dirname "$0")/.."

NAME=yetkaz-tunnel
RECREATE=0
[ "${1:-}" = "--recreate" ] && RECREATE=1

say() { printf '→ %s\n' "$*"; }
die() { printf '✗ %s\n' "$*" >&2; exit 1; }

# .env dan qiymat (CR va qo'shtirnoqsiz). Faqat o'qiydi.
env_get() { grep -E "^$1=" .env 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '\r' | sed -E "s/^[\"']//; s/[\"']$//" || true; }

http_code() { curl -s -m 20 -o /dev/null -w '%{http_code}' "$1" 2>/dev/null || echo 000; }

tunnel_url() { docker logs "$NAME" 2>&1 | grep -Eo 'https://[a-z0-9-]+\.trycloudflare\.com' | tail -1 || true; }

[ -f .env ] || die ".env topilmadi."
docker info >/dev/null 2>&1 || die "Docker ishlamayapti — Docker Desktop'ni ishga tushiring."

PORT="$(env_get APP_FORWARD_PORT)"; PORT="${PORT:-8010}"

recreate_tunnel() {
  say "tunnel konteyneri qayta yaratilmoqda (localhost:${PORT})…"
  docker rm -f "$NAME" >/dev/null 2>&1 || true
  docker run -d --name "$NAME" --network host --restart unless-stopped \
    cloudflare/cloudflared:latest tunnel --protocol http2 --url "http://localhost:${PORT}" --no-autoupdate >/dev/null
  for _ in $(seq 1 40); do
    [ -n "$(tunnel_url)" ] && return 0
    sleep 1
  done
  docker logs "$NAME" 2>&1 | tail -15 >&2
  die "tunnel manzili topilmadi (internet/VPN'ni tekshiring)."
}

# 1) Tunnel manzili ---------------------------------------------------------------------------------------------------
RUNNING="$(docker ps --filter "name=^${NAME}$" --filter status=running --format '{{.Names}}')"
if [ "$RECREATE" = 1 ] || [ -z "$RUNNING" ]; then
  recreate_tunnel
  URL="$(tunnel_url)"
else
  URL="$(tunnel_url)"
  if [ -z "$URL" ] || [ "$(http_code "$URL/app")" != "200" ]; then
    say "joriy tunnel javob bermayapti (${URL:-manzil topilmadi}) — qayta yaratiladi."
    recreate_tunnel
    URL="$(tunnel_url)"
  else
    say "tunnel tirik, mavjud manzil olinadi."
  fi
fi

# Yangi manzil DNS'ga tarqalguncha bir necha soniya ketishi mumkin.
for _ in $(seq 1 30); do
  [ "$(http_code "$URL/app")" = "200" ] && break
  sleep 2
done
[ "$(http_code "$URL/app")" = "200" ] || die "$URL/app javob bermayapti (200 emas). Ilova ishlayaptimi: docker compose ps"

MINI_APP_URL="${URL}/app"
say "yangi manzil: ${MINI_APP_URL}"

# 2) .env — faqat TELEGRAM_MINI_APP_URL ------------------------------------------------------------------------------
OLD="$(env_get TELEGRAM_MINI_APP_URL)"
if [ "$OLD" = "$MINI_APP_URL" ]; then
  say ".env allaqachon shu manzilda."
elif grep -q '^TELEGRAM_MINI_APP_URL=' .env; then
  sed -i "s#^TELEGRAM_MINI_APP_URL=.*#TELEGRAM_MINI_APP_URL=${MINI_APP_URL}#" .env
  say ".env yangilandi (faqat TELEGRAM_MINI_APP_URL; eski: ${OLD:-—})."
else
  printf '\nTELEGRAM_MINI_APP_URL=%s\n' "$MINI_APP_URL" >> .env
  say ".env ga TELEGRAM_MINI_APP_URL qo'shildi."
fi

# 3) Tekshiruv: sahifa, asosiy JS/CSS, API ----------------------------------------------------------------------------
HTML="$(curl -s -m 20 "$URL/app" || true)"
ASSETS="$(printf '%s' "$HTML" | grep -Eo 'https://[^" ]+/build/assets/[A-Za-z0-9._-]+\.(js|css)' | grep -F "$URL" | sort -u | head -4 || true)"
BAD=0
for a in $ASSETS; do
  [ "$(http_code "$a")" = "200" ] || { printf '  ✗ yuklanmadi: %s\n' "${a##*/}"; BAD=1; }
done
[ -n "$ASSETS" ] || { echo "  ✗ sahifada build asset topilmadi (npm run build kerakmi?)"; BAD=1; }
printf '%s' "$HTML" | grep -q 'http://' && { echo "  ⚠ sahifada http:// havola bor (mixed content bo'lishi mumkin)"; BAD=1; }
API="$(http_code "$URL/api/me")"
say "tekshiruv: /app=200, asset=$([ "$BAD" = 0 ] && echo 'ok' || echo 'MUAMMO'), /api/me=${API} (401 normal)"
[ "$API" = "401" ] || [ "$API" = "200" ] || BAD=1

# 4) Konteynerlar: config keshi va xotiradagi kod --------------------------------------------------------------------
RUNNING_SERVICES="$(docker compose ps --status running --services 2>/dev/null || true)"
if printf '%s\n' "$RUNNING_SERVICES" | grep -qx app; then
  docker compose exec -T app php artisan config:clear >/dev/null 2>&1 || true
  docker compose exec -T app php artisan octane:reload >/dev/null 2>&1 || true
  say "config:clear + octane:reload bajarildi."
  RESTART=""
  for s in bot horizon scheduler courier-bot; do
    printf '%s\n' "$RUNNING_SERVICES" | grep -qx "$s" && RESTART="$RESTART $s"
  done
  if [ -n "$RESTART" ]; then
    # shellcheck disable=SC2086
    docker compose restart $RESTART >/dev/null 2>&1 || true
    say "qayta ishga tushirildi:${RESTART}"
  fi
else
  echo "⚠ 'app' konteyneri ishlamayapti — avval: docker compose up -d"
fi

# 5) Telegram (lokal test bot): menyu tugmasi --------------------------------------------------------------------------
TOKEN="$(env_get TELEGRAM_BOT_TOKEN)"
tg() { # tg <metod> [json] — token argv'ga tushmaydi
  local body="${2:-}"
  if [ -n "$body" ]; then
    printf 'url = "https://api.telegram.org/bot%s/%s"\n' "$TOKEN" "$1" | curl -s -m 20 -K - -H 'Content-Type: application/json' -d "$body" || true
  else
    printf 'url = "https://api.telegram.org/bot%s/%s"\n' "$TOKEN" "$1" | curl -s -m 20 -K - || true
  fi
}
menu_url() { tg getChatMenuButton | grep -Eo '"url":"[^"]*"' | head -1 | sed -E 's/"url":"//; s/"$//' || true; }

if [ -z "$TOKEN" ]; then
  echo "⚠ TELEGRAM_BOT_TOKEN .env da yo'q — menyu tugmasi yangilanmadi."
else
  BOT="$(tg getMe | grep -Eo '"username":"[^"]+"' | sed -E 's/"username":"//; s/"$//' || true)"
  [ -n "$BOT" ] || die "Telegram tokeni ishlamayapti (getMe javobsiz)."
  say "Telegram bot: @${BOT}"

  WH="$(tg getWebhookInfo | grep -Eo '"url":"[^"]*"' | head -1 | sed -E 's/"url":"//; s/"$//' || true)"
  if [ -n "$WH" ]; then
    echo "⚠ Bu botda webhook o'rnatilgan (${WH%%/webhook/*}/webhook/***). Lokal bot odatda polling'da — webhook"
    echo "  tunnelning eski manzilida bo'lsa xabarlar kelmaydi. Kerak bo'lmasa: o'chirish uchun deleteWebhook."
  else
    say "webhook yo'q (polling) — setWebhook kerak emas."
  fi

  TEXT="$(tg getChatMenuButton | grep -Eo '"text":"[^"]*"' | head -1 | sed -E 's/"text":"//; s/"$//' || true)"
  TEXT="${TEXT:-Yetkaz}"
  RES="$(tg setChatMenuButton "{\"menu_button\":{\"type\":\"web_app\",\"text\":\"${TEXT}\",\"web_app\":{\"url\":\"${MINI_APP_URL}\"}}}")"
  printf '%s' "$RES" | grep -q '"ok":true' || die "setChatMenuButton muvaffaqiyatsiz."
  say "menyu tugmasi yuborildi («${TEXT}»); Telegram qo'llashini kutilmoqda…"

  # Telegram bu o'zgarishni ba'zan bir necha daqiqa kechikib qo'llaydi (getChatMenuButton eski URL'ni qaytaradi).
  APPLIED=0
  for _ in $(seq 1 12); do
    [ "$(menu_url)" = "$MINI_APP_URL" ] && { APPLIED=1; break; }
    sleep 10
  done
  if [ "$APPLIED" = 1 ]; then
    say "menyu tugmasi tasdiqlandi."
  else
    echo "⚠ Telegram hali yangi URL'ni qaytarmayapti (odatda 1–4 daqiqa kechikadi). Biroz kutib qayta tekshiring:"
    echo "  ./scripts/refresh-tunnel.sh   (idempotent: tunnelni qayta yaratmaydi)"
  fi
fi

echo
if [ "$BAD" = 0 ]; then echo "✓ Tayyor."; else echo "⚠ Tayyor, lekin yuqoridagi muammolarni ko'ring."; fi
cat <<EOF

  Mini App:  ${MINI_APP_URL}

  Telegram'da: botga /start yuboring (inline tugmalar yangi manzilni oladi). Menyu tugmasi ochilmasa —
  Telegram'ni to'liq yopib qayta oching.
  To'xtatish: docker stop ${NAME}      Loglar: docker logs -f ${NAME}
  JS o'zgartirsangiz: frontend'ni qayta build qiling (README «Frontend»).
EOF
