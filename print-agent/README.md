# Yetkaz print agent

Oshxonadagi kompyuterda ishlaydi. Backend'ga WebSocket (Reverb) bilan ulanib,
yangi buyurtma chekini chop etadi. Yagona PHP bo'lmagan qism.

- Faqat o'z restorani kanalini tinglaydi: `private-restaurant.{id}.print`
- `print.requested` kelganда chekni chop etadi (simulate = fayl, network = printer)
- **Lokal navbat (SQLite `queue.db`):** printer o'chgan / internet uzilgan bo'lsa
  chek yo'qolmaydi — qayta ulanganда yoki har 15 s da avtomat qayta uriniladi
- Chop etilgach backend'ga tasdiq yuboradi → `orders.printed_at`

## Talablar

- Python **3.9+**
- Internet (backend + Reverb domenga ulanish)
- `network` rejimi uchun: ESC/POS termoprinter, LAN'da `PRINTER_HOST:9100`

## O'rnatish (oshxona kompyuteri)

```bash
cd print-agent
python3 -m venv .venv
. .venv/bin/activate                 # Windows: .venv\Scripts\activate
pip install -r requirements.txt

cp .env.example .env
#  .env ni to'ldiring:
#    BACKEND_URL=https://yetqaz.uz
#    RESTAURANT_ID=<restoran ID>
#    PRINT_AGENT_TOKEN=<serverdagi /root/<restoran>-print-agent-token.txt dan>
#    REVERB_HOST=yetqaz.uz  REVERB_PORT=443  REVERB_SCHEME=https  REVERB_PATH=/reverb
#    REVERB_APP_KEY=<serverdagi .env REVERB_APP_KEY bilan bir xil>
#    PRINTER_MODE=simulate            # sinov; keyin: network

python agent.py
```

Ishga tushganда ko'rinishi kerak:

```
[HH:MM:SS] Yetkaz print agent — restoran 4, rejim: simulate
[HH:MM:SS] backend: https://yetqaz.uz
[HH:MM:SS] reverb:  wss://yetqaz.uz:443/reverb/app/*  kanal: private-restaurant.4.print
[HH:MM:SS] ulandi (socket NNNN.NNNN), kanalga obuna: private-restaurant.4.print
[HH:MM:SS] obuna tasdiqlandi — chek so'rovlarini kutmoqda
```

Buyurtma kelganда (`simulate`): `output/<raqam>-<vaqt>.txt` yoziladi va chek
konsolga chop etiladi; so'ng `backend tasdiqladi: order <id>`.

## Server tomonida (bir marta, restoranga token)

```bash
docker compose -f docker-compose.prod.yml exec app \
  php artisan print-agent:provision "Donix" --out=/root/donix-print-agent-token.txt
```

`pos_type=escpos` qiladi, token yaratadi (bazaga + faylga `0600`), Reverb kanal
nomini va `RESTAURANT_ID` ni chiqaradi. Token ekranga chiqmaydi.
Almashtirish kerak bo'lsa: `--rotate`.

## Rejimlar

| `PRINTER_MODE` | Nima qiladi |
|---|---|
| `simulate` | Chekni `output/<raqam>.txt` ga yozadi va konsolga chiqaradi. Printer shart emas. |
| `network` | ESC/POS baytlarini `PRINTER_HOST:PRINTER_PORT` (odatда `:9100`) ga yuboradi. |

## Doimiy ishlash (ixtiyoriy)

Linux oshxona kompyuteri uchun `systemd` unit yoki `screen`/`tmux`; Windows uchun
Task Scheduler ("At startup"). Agent o'zi uzilishда qayta ulanadi.
