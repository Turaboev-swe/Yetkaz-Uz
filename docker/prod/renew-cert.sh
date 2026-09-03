#!/usr/bin/env bash
# Yetkaz — SSL sertifikatni yangilash + nginx reload.
# Cron: docker/prod/yetkaz-cert-renew.cron -> /etc/cron.d/yetkaz-cert-renew
#
# certbot renew — 30 kundan kam qolganда yangilaydi, aks holda no-op.
set -euo pipefail

cd "${YETKAZ_DIR:-/opt/yetkaz}"
COMPOSE="docker compose -f docker-compose.prod.yml"

$COMPOSE run --rm certbot renew --webroot -w /var/www/certbot --quiet
$COMPOSE exec -T nginx nginx -s reload

echo "$(date -u +%FT%TZ)  certbot renew + nginx reload OK"
