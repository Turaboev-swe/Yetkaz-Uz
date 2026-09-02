#!/usr/bin/env bash
# Yetkaz — kunlik Postgres backup (PROD-7).
#
#   bash /opt/yetkaz/docker/prod/backup.sh
#
# Cron: docker/prod/yetkaz-backup.cron -> /etc/cron.d/yetkaz-backup (03:00 Asia/Tashkent).
# Tiklash: docs/deploy.md "Backup va tiklash".
set -euo pipefail

PROJECT_DIR="${YETKAZ_DIR:-/opt/yetkaz}"
BACKUP_DIR="${YETKAZ_BACKUP_DIR:-$PROJECT_DIR/backups}"
RETAIN_DAYS="${YETKAZ_BACKUP_RETAIN:-14}"
DISK_WARN_PCT="${YETKAZ_DISK_WARN:-85}"
COMPOSE="docker compose -f $PROJECT_DIR/docker-compose.prod.yml"

cd "$PROJECT_DIR"
mkdir -p "$BACKUP_DIR"

stamp=$(date +%Y-%m-%d)
file="$BACKUP_DIR/yetkaz-$stamp.sql.gz"
tmp="$file.tmp"

# --no-owner / --no-privileges — boshqa muhitga ham tiklash oson bo'lsin.
$COMPOSE exec -T postgres pg_dump -U yetkaz -d yetkaz --no-owner --no-privileges \
  | gzip -9 > "$tmp"

# bo'sh yoki juda kichik dump = xato
if [ "$(stat -c%s "$tmp")" -lt 1000 ]; then
    echo "XATO: dump juda kichik ($(stat -c%s "$tmp") bayt) — saqlanmadi" >&2
    rm -f "$tmp"
    exit 1
fi
mv "$tmp" "$file"

# Retention — RETAIN_DAYS kundan eski dumplar
find "$BACKUP_DIR" -maxdepth 1 -name 'yetkaz-*.sql.gz' -mtime "+$RETAIN_DAYS" -print -delete

# Disk ogohlantirish (oddiy — murakkab monitoring emas)
use=$(df --output=pcent "$BACKUP_DIR" | tail -1 | tr -dc '0-9')
warn=""
if [ "${use:-0}" -ge "$DISK_WARN_PCT" ]; then
    warn="  !!! DISK ${use}% — tozalash kerak"
fi

size=$(du -h "$file" | cut -f1)
count=$(find "$BACKUP_DIR" -maxdepth 1 -name 'yetkaz-*.sql.gz' | wc -l)
echo "$(date -u +%FT%TZ)  OK  $file  ($size)  jami:${count}  disk:${use}%${warn}"
