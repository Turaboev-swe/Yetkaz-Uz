#!/usr/bin/env bash
# Yetkaz — birinchi SSL sertifikat (Let's Encrypt, webroot).
#
#   bash docker/prod/init-cert.sh
#   CERT_EMAIL=you@example.com bash docker/prod/init-cert.sh   # expiry ogohlantirish uchun
#
# Talab: DNS yetqaz.uz -> shu server, nginx (prod.conf) port 80 da ishlab turibdi.
set -euo pipefail

cd "${YETKAZ_DIR:-/opt/yetkaz}"
DOMAINS="${CERT_DOMAINS:-yetqaz.uz www.yetqaz.uz}"
EMAIL="${CERT_EMAIL:-}"
COMPOSE="docker compose -f docker-compose.prod.yml"

d_args=()
for d in $DOMAINS; do d_args+=(-d "$d"); done

if [ -n "$EMAIL" ]; then
    email_args=(--email "$EMAIL" --no-eff-email)
else
    email_args=(--register-unsafely-without-email)
    echo "DIQQAT: emailsiz — Let's Encrypt expiry ogohlantirishlari kelmaydi."
    echo "        (renewal cron baribir avtomat yangilaydi.)"
fi

# ACME challenge nginx port 80 orqali ko'rinishi kerak
$COMPOSE up -d nginx

$COMPOSE run --rm certbot certonly \
    --webroot -w /var/www/certbot \
    "${d_args[@]}" "${email_args[@]}" \
    --agree-tos --non-interactive --keep-until-expiring "$@"

echo
echo "=== Sertifikat holati ==="
$COMPOSE run --rm certbot certificates

cat <<'NEXT'

Keyingi qadamlar:
  1. .env:  NGINX_CONF=prod-ssl.conf
  2. docker compose -f docker-compose.prod.yml up -d nginx
  3. curl -I https://yetqaz.uz/up
NEXT
