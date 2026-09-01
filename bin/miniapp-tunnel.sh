#!/usr/bin/env bash
#
# Mini App uchun public HTTPS tunnel (cloudflared quick tunnel).
#
# Bepul trycloudflare.com manzili tunnel har qayta ishga tushganda o'zgaradi
# (masalan kompyuter reboot bo'lsa). Bu skript:
#   1. tunnel konteynerini qayta yaratadi
#   2. yangi manzilni oladi
#   3. .env dagi TELEGRAM_MINI_APP_URL ni yangilaydi
#   4. config keshini tozalab, bot'ni qayta ishga tushiradi
#   5. BotFather uchun manzilni chiqaradi
#
# Ishlatish:  ./bin/miniapp-tunnel.sh        (yoki: make tunnel)
#
set -euo pipefail
cd "$(dirname "$0")/.."

NAME=yetkaz-tunnel
PORT="${APP_FORWARD_PORT:-8010}"

echo "→ tunnel konteyneri qayta yaratilmoqda…"
docker rm -f "$NAME" >/dev/null 2>&1 || true
docker run -d --name "$NAME" --network host --restart unless-stopped \
  cloudflare/cloudflared:latest tunnel --url "http://localhost:${PORT}" --no-autoupdate >/dev/null

echo "→ manzil kutilmoqda…"
URL=""
for _ in $(seq 1 30); do
  URL=$(docker logs "$NAME" 2>&1 | grep -Eo 'https://[a-z0-9-]+\.trycloudflare\.com' | head -1 || true)
  [ -n "$URL" ] && break
  sleep 1
done

if [ -z "$URL" ]; then
  echo "✗ tunnel manzili topilmadi. Loglar:" >&2
  docker logs "$NAME" 2>&1 | tail -20 >&2
  exit 1
fi

MINI_APP_URL="${URL}/app"

if grep -q '^TELEGRAM_MINI_APP_URL=' .env; then
  sed -i "s#^TELEGRAM_MINI_APP_URL=.*#TELEGRAM_MINI_APP_URL=${MINI_APP_URL}#" .env
else
  printf '\nTELEGRAM_MINI_APP_URL=%s\n' "$MINI_APP_URL" >> .env
fi
echo "→ .env yangilandi: TELEGRAM_MINI_APP_URL=${MINI_APP_URL}"

if docker compose ps --status running --services 2>/dev/null | grep -qx app; then
  docker compose exec -T app php artisan optimize:clear >/dev/null 2>&1 || true
  docker compose restart bot >/dev/null 2>&1 || true
  echo "→ config keshi tozalandi, bot qayta ishga tushdi"
else
  echo "⚠ 'app' konteyneri ishlamayapti — 'make up' qiling"
fi

cat <<EOF

✓ Tayyor.

  Mini App:   ${MINI_APP_URL}

  1. Telegram'da botga /start yuboring (inline tugma yangi manzilni oladi).
  2. Agar BotFather menyu tugmasini ishlatsangiz — uni ham yangilang:
     BotFather → /mybots → bot → Bot Settings → Menu Button → ${MINI_APP_URL}

  JS o'zgartirsangiz:  npm run build
  Tunnel loglari:      docker logs -f ${NAME}
EOF
