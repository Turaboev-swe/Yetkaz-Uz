# Yetkaz print agent

Oshxonadagi kompyuterda ishlaydi. Backend'ga WebSocket (Reverb) bilan ulanib,
yangi buyurtma chekini chop etadi. Yagona PHP bo'lmagan qism.

- Faqat o'z restorani kanalini tinglaydi: `private-restaurant.{id}.print`
- `print.requested` kelganda chekni chop etadi (simulate = fayl, network = printer)
- **Lokal navbat (SQLite `queue.db`):** printer o'chgan / internet uzilgan bo'lsa
  chek yo'qolmaydi — qayta ulanganda yoki har 15 s da avtomat qayta uriniladi
- Chop etilgach backend'ga tasdiq yuboradi → `orders.printed_at`
- Log: konsolga va aylanuvchi faylga (`agent.log`)

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

Ishga tushganda ko'rinishi kerak:

```
2026-11-02 09:00:00 Yetkaz print agent — restoran 4, rejim: simulate
2026-11-02 09:00:00 backend: https://yetqaz.uz
2026-11-02 09:00:00 reverb:  wss://yetqaz.uz:443/reverb/app/*  kanal: private-restaurant.4.print
2026-11-02 09:00:01 ulandi (socket NNNN.NNNN), kanalga obuna: private-restaurant.4.print
2026-11-02 09:00:01 obuna tasdiqlandi — chek so'rovlarini kutmoqda
```

Buyurtma kelganda (`simulate`): `output/<raqam>-<vaqt>.txt` yoziladi va chek
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
| `network` | ESC/POS baytlarini `PRINTER_HOST:PRINTER_PORT` (odatda `:9100`) ga yuboradi. |

## Chek formati va sinov cheki

Chek **backend'da** tuziladi (agent faqat baytlarni printerga uzatadi). Sozlamalar —
`/admin` → Restoranlar → restoran → «POS» (pos_type = escpos bo'lganda):

| Sozlama | Qiymat |
|---|---|
| Chek qog'ozi | **80 mm** (42 belgi, standart) yoki **58 mm** (32 belgi) |
| Kodlash jadvali (`ESC t n`) | standart **16** (WPC1252, Xprinter/Epson-mos) |

Chekka **faqat ASCII** yoziladi: kirill harflar lotinga o'giriladi (o'qilishi uchun:
«Плов» → «Plov», «ў» → «o'»), emoji va boshqa belgilar olib tashlanadi — shuning uchun
printerning kodlash jadvaliga bog'liq «iflos» belgilar chiqmaydi. Chekda mijoz ismi va
telefon (olib ketish buyurtmasida ham), to'lov usuli bor.

**Sinov cheki** (haqiqiy printerni sozlashda): uzun nom, kirill, emoji va `'` bilan namunaviy chek:

```bash
docker compose -f docker-compose.prod.yml exec app php artisan print-agent:test-receipt "Donix"
docker compose -f docker-compose.prod.yml exec app php artisan print-agent:test-receipt "Donix" --table   # + 0x80-0xFF kodlash jadvali
docker compose -f docker-compose.prod.yml exec app php artisan print-agent:test-receipt "Donix" --dry     # yubormasdan faqat matn
```

Chek tepasidagi `1234567890…` chizig'i qog'oz kengligiga to'liq sig'ishi kerak. Sig'masa — kenglik
sozlamasi noto'g'ri. Agent o'chiq bo'lsa sinov cheki **navbatga olinmaydi**.

## Monitoring (panelda ogohlantirishlar)

- Agent har 30 s da **heartbeat** yuboradi. 2 daqiqadan ko'p jim bo'lsa oshxona panelida qizil
  «Printer agenti o'chiq» banneri va signal, `/admin` Dashboard'da «Chek printerlari» jadvali chiqadi.
- Buyurtma yaratilgandan 2 daqiqada `printed_at` to'lmasa — «Chek chiqmadi» belgisi (agent o'chiq,
  printer ishlamayotgan bo'lsa ham; Reverb xatosiga tayanilmaydi). Agent keyin chop etsa belgi o'zi ketadi.
- Agent faqat oxirgi **3 soat** ichidagi, bekor qilinmagan va yetkazilmagan buyurtmalarni qayta oladi;
  eskilari chop etilmaydi, lekin «Chek chiqmadi» bo'lib ko'rinib turadi.

## Ishonchlilik (qanday kafolatlar bor)

| Holat | Agent nima qiladi |
|---|---|
| Printer o'chiq / ulanmagan | Buyurtma navbatda qoladi, har 15 s da qayta uriniladi. Tartib saqlanadi (birinchisi chiqmaguncha keyingilari urinilmaydi). |
| Internet / backend yo'q | Navbatdagilar baribir chop etiladi. Tasdiq (`printed_at`) keyinroq, aloqa tiklanganda qayta yuboriladi — chek takror chiqmaydi. |
| WebSocket ishlamayapti yoki obuna bo'lmadi | Har ~60 s da backend'dan `pending` so'raladi (zaxira yo'l). Obuna xatosida 2, 4, 8, 16 s kutib qayta uriniladi, so'ng WS uzilib qayta ulanadi. |
| Chek chiqdi, lekin agent shu zahoti o'chdi | Qayta ishga tushganda chek **yana chiqishi mumkin**. |
| Ikki oqim bir vaqtda | Chop etish yagona bajaruvchida (qulf) — bir buyurtma ikki marta chiqmaydi. |

**Kamida bir marta (at-least-once):** maqsad — chek yo'qolishi emas; kamdan-kam takror
chiqqani ma'qul. Shuning uchun chop etish va "chop etildi" belgisi orasida o'chib qolish
takror chekka olib kelishi mumkin.

Tasdiqlangan qatorlar 7 kundan keyin `queue.db` dan o'chiriladi (`ACKED_KEEP_DAYS`).

## Log

`agent.log` (agent papkasida) — har biri `LOG_MAX_BYTES` (1 MB) gacha, `LOG_BACKUPS` (5) ta
zaxira bilan aylanadi (`agent.log.1` …). Joyini `.env` da `LOG_FILE` bilan o'zgartiring.
Konsolga ham bir xil chiqadi.

## Doimiy ishlash

### Windows — NSSM bilan servis (tavsiya)

NSSM agentni Windows servisi qiladi: kompyuter yonganda o'zi ishga tushadi, xato bilan
tugasa qayta ishga tushiradi, foydalanuvchi tizimga kirishi shart emas.

1. **Python** o'rnating ([python.org](https://www.python.org/downloads/), «Add python.exe to PATH»).
2. Agent papkasini nusxalang, masalan `C:\yetkaz\print-agent`, va muhitni tayyorlang
   (**oddiy** cmd/PowerShell'da):

   ```bat
   cd C:\yetkaz\print-agent
   python -m venv .venv
   .venv\Scripts\pip install -r requirements.txt
   copy .env.example .env
   notepad .env
   ```

3. **Avval qo'lda sinang:** `.venv\Scripts\python agent.py` — «obuna tasdiqlandi» ko'rinsin,
   so'ng `Ctrl+C`.
4. **NSSM** ni [nssm.cc](https://nssm.cc/download) dan yuklab oching, masalan `C:\nssm\win64\nssm.exe`.
5. **Servisni o'rnating** (Administrator sifatida ochilgan cmd'da):

   ```bat
   C:\nssm\win64\nssm.exe install YetkazPrintAgent C:\yetkaz\print-agent\.venv\Scripts\python.exe agent.py
   C:\nssm\win64\nssm.exe set YetkazPrintAgent AppDirectory C:\yetkaz\print-agent
   C:\nssm\win64\nssm.exe set YetkazPrintAgent DisplayName "Yetkaz Print Agent"
   C:\nssm\win64\nssm.exe set YetkazPrintAgent Start SERVICE_AUTO_START
   C:\nssm\win64\nssm.exe set YetkazPrintAgent AppEnvironmentExtra PYTHONUNBUFFERED=1
   C:\nssm\win64\nssm.exe set YetkazPrintAgent AppExit Default Restart
   C:\nssm\win64\nssm.exe set YetkazPrintAgent AppRestartDelay 5000
   C:\nssm\win64\nssm.exe set YetkazPrintAgent AppStdout C:\yetkaz\print-agent\service.log
   C:\nssm\win64\nssm.exe set YetkazPrintAgent AppStderr C:\yetkaz\print-agent\service.log
   C:\nssm\win64\nssm.exe set YetkazPrintAgent AppRotateFiles 1
   C:\nssm\win64\nssm.exe set YetkazPrintAgent AppRotateOnline 1
   C:\nssm\win64\nssm.exe set YetkazPrintAgent AppRotateBytes 1000000
   C:\nssm\win64\nssm.exe start YetkazPrintAgent
   ```

   `AppDirectory` muhim: `.env`, `queue.db` va `agent.log` shu papkadan o'qiladi/yoziladi.

6. **Tekshirish:** `nssm status YetkazPrintAgent` → `SERVICE_RUNNING`; `agent.log` oxirida
   «obuna tasdiqlandi» bo'lishi kerak. Kompyuterni qayta ishga tushirib, servis o'zi
   ko'tarilishini ham tekshiring.

Boshqarish:

```bat
nssm restart YetkazPrintAgent
nssm stop YetkazPrintAgent
nssm edit YetkazPrintAgent          :: oyna orqali sozlamalar
nssm remove YetkazPrintAgent confirm
```

**Yangilash:** `nssm stop YetkazPrintAgent` → fayllarni almashtiring →
`.venv\Scripts\pip install -r requirements.txt` → `nssm start YetkazPrintAgent`.
`.env` va `queue.db` ni **o'chirmang** (navbatdagi cheklar shu yerda).

Muammo bo'lsa avval `agent.log`, keyin `service.log` ga qarang (Python ishga tushmagan bo'lsa
xato shu yerda bo'ladi).

### Linux

`systemd` unit (`Restart=always`, `WorkingDirectory=<agent papkasi>`,
`ExecStart=<papka>/.venv/bin/python agent.py`) yoki `screen`/`tmux`. Agent o'zi uzilishda qayta ulanadi.

## Testlar

```bash
pip install -r requirements.txt -r requirements-dev.txt
python -m pytest
```

Testlar vaqtinchalik papkada ishlaydi (haqiqiy `.env`, `queue.db`, `agent.log` ga tegmaydi).
