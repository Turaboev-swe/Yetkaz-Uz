#!/usr/bin/env bash
# 2 GB swap fayl — 4 GB RAM serverда xavfsizlik zaxirasi (Docker build + Octane).
# Idempotent: qayta ishga tushirilsa hech narsani buzmaydi.
#
# Serverда root sifatida:  bash docker/prod/setup-swap.sh
set -euo pipefail

SWAPFILE=/swapfile
SIZE_MB=2048

if [[ $EUID -ne 0 ]]; then
    echo "root kerak: sudo bash $0" >&2
    exit 1
fi

if swapon --show=NAME --noheadings | grep -qx "$SWAPFILE"; then
    echo "swap allaqachon faol:"
    swapon --show
    exit 0
fi

if [[ -e $SWAPFILE ]]; then
    echo "$SWAPFILE mavjud, lekin faol emas — qo'lda tekshiring." >&2
    exit 1
fi

echo "==> $SWAPFILE yaratilmoqda (${SIZE_MB} MB)"
if ! fallocate -l "${SIZE_MB}M" "$SWAPFILE" 2>/dev/null; then
    dd if=/dev/zero of="$SWAPFILE" bs=1M count="$SIZE_MB" status=progress
fi
chmod 600 "$SWAPFILE"
mkswap "$SWAPFILE"
swapon "$SWAPFILE"

# Reboot'dan keyin ham
if ! grep -qE "^\s*${SWAPFILE}\s" /etc/fstab; then
    echo "$SWAPFILE none swap sw 0 0" >> /etc/fstab
    echo "==> /etc/fstab ga yozildi"
fi

# Swapni faqat RAM tugaganda ishlat (SSD saqlash uchun)
sysctl -w vm.swappiness=10 >/dev/null
sysctl -w vm.vfs_cache_pressure=50 >/dev/null
cat > /etc/sysctl.d/99-yetkaz.conf <<'EOF'
vm.swappiness=10
vm.vfs_cache_pressure=50
EOF

echo "==> tayyor"
swapon --show
free -h
