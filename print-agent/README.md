# Yetkaz print agent

Oshxonadagi kompyuterda ishlaydi. Backend'ga WebSocket (Reverb) bilan ulanib,
yangi buyurtma chekini chop etadi. Yagona PHP bo'lmagan qism.

- Faqat o'z restorani kanalini tinglaydi: `private-restaurant.{id}.print`
- `print.requested` kelganda chekni chop etadi (simulate = fayl, network = LAN printer,
  **windows = USB printer** Windows orqali, usb-linux = Linux'da USB)
- **Lokal navbat (SQLite `queue.db`):** printer o'chgan / internet uzilgan bo'lsa
  chek yo'qolmaydi — qayta ulanganda yoki har 15 s da avtomat qayta uriniladi
- Chop etilgach backend'ga tasdiq yuboradi → `orders.printed_at`
- Log: konsolga va aylanuvchi faylga (`agent.log`)

## Talablar

- Python **3.9+**
- Internet (backend + Reverb domenga ulanish)
- `network` rejimi uchun: ESC/POS termoprinter, LAN'da `PRINTER_HOST:9100`
- `windows` rejimi uchun (USB printer): Windows + printer drayveri + `pywin32` (`pip install -r requirements.txt` o'zi
  o'rnatadi, faqat Windows'da) — [qadam-baqadam yo'riqnoma](#windows-kompyuterida-ornatish--usb-printer-xprinter-xp-80t)
- `usb-linux` rejimi uchun: Linux va `/dev/usb/lp0` ga yozish huquqi

## Windows kompyuterida o'rnatish — USB printer (Xprinter XP-80T)

Bu bo'lim oshxonadagi **Windows** kompyuter va **faqat USB** bilan ulanadigan printer (masalan Xprinter XP-80T,
80 mm) uchun. Dasturlash bilimi kerak emas: buyruqlarni ketma-ket nusxalab bajaring.

> Agent printerga **Windows chop etish tizimi (spooler)** orqali yozadi — shuning uchun avval Windows'da
> printer drayveri o'rnatilgan bo'lishi shart.

### 1-qadam. Printerni ulang

USB kabelni kompyuterga, tok kabelini rozetkaga ulang, printerni yoqing va qog'oz soling.

### 2-qadam. Xprinter drayverini o'rnating va printer nomini ko'ring

1. Xprinter rasmiy sayti (**xprinter.net** → Download → Driver) dan **XP-80T** drayverini yuklab oling va o'rnating
   (o'rnatish dasturida port sifatida **USB** ni tanlang).
2. **Sozlamalar → Bluetooth va qurilmalar → Printerlar va skanerlar** (Windows 10: *Devices → Printers & scanners*) ga
   kiring. Ro'yxatda printeringiz ko'rinishi kerak (masalan **XP-80T** yoki **XPrinter XP-80T**).
3. Printerni bosing → **Printer xususiyatlari** → **Sinov sahifasini chop etish** (*Print Test Page*). Qog'ozga
   sahifa chiqsa — drayver ishlayapti.

Printer nomini keyingi qadamlarda bir xil yozasiz. Qulay bo'lishi uchun nomni oddiyroq qilib o'zgartirishingiz mumkin
(*Printer xususiyatlari → nomni o'zgartirish*), masalan `XP-80T`.

### 3-qadam. Python o'rnating

1. [python.org/downloads](https://www.python.org/downloads/) dan Python (3.9 yoki yangi) yuklab oling.
2. O'rnatishni boshlaganda **pastdagi «Add python.exe to PATH» belgisini albatta qo'ying**, keyin *Install Now*.
3. Tekshirish: **Win + R** → `cmd` → Enter. Ochilgan oynaga `python --version` yozing. `Python 3.…` chiqsa — tayyor.

### 4-qadam. Agent dasturini joylashtiring va kutubxonalarni o'rnating

1. Agent papkasini (`print-agent`) kompyuterga nusxalang, masalan `C:\yetkaz\print-agent`.
2. `cmd` oynasida (Internet kerak) ketma-ket bajaring:

   ```bat
   cd C:\yetkaz\print-agent
   python -m venv .venv
   .venv\Scripts\pip install -r requirements.txt
   ```

   Shu bilan printer bilan ishlash uchun kerakli **pywin32** ham o'rnatiladi (faqat Windows'da).

### 5-qadam. `.env` faylini to'ldiring

```bat
copy .env.example .env
notepad .env
```

Quyidagilarni to'ldiring (qolganini o'zgartirmang):

| Kalit | Qayerdan olinadi |
|---|---|
| `BACKEND_URL` | `https://yetqaz.uz` |
| `RESTAURANT_ID` | Restoran raqami. Dasturchi serverda `print-agent:provision` buyrug'ini bajarganda chiqadi. |
| `PRINT_AGENT_TOKEN` | **Maxfiy kalit.** Dasturchi serverda yaratadi (pastga qarang) va sizga xavfsiz yo'l bilan beradi. Hech kimga ko'rsatmang. |
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`, `REVERB_PATH` | `.env.example` dagi tayyor qiymatlar (`yetqaz.uz`, `443`, `https`, `/reverb`) |
| `REVERB_APP_KEY` | Serverdagi `.env` dagi `REVERB_APP_KEY` bilan aynan bir xil (dasturchidan so'rang) |
| `PRINTER_MODE` | `windows` |
| `PRINTER_NAME` | Printer nomi — keyingi qadamda aniqlaymiz |

**Token qayerdan olinadi (dasturchi uchun):** serverda bitta buyruq restoranni printerga tayyorlaydi va tokenni faylga yozadi
(ekranga chiqarmaydi):

```bash
docker compose -f docker-compose.prod.yml exec app \
  php artisan print-agent:provision "Guzar Kafe" --out=/root/guzar-print-agent-token.txt
```

Buyruq `RESTAURANT_ID` ni ham ko'rsatadi. Token faylining ichidagi matnni `PRINT_AGENT_TOKEN=` dan keyin yozing.

### 6-qadam. Printer nomini aniqlang

```bat
.venv\Scripts\python agent.py --list-printers
```

Windows'da o'rnatilgan printerlar ro'yxati chiqadi, masalan:

```
O'rnatilgan printerlar:
  - Microsoft Print to PDF
  - XP-80T
.env ga shu nomlardan birini yozing: PRINTER_NAME=<nom>
```

Kerakli nomni **aynan shunday** (katta-kichik harf va bo'shliqlari bilan) `.env` ga yozing: `PRINTER_NAME=XP-80T`.
Nom bo'sh yoki noto'g'ri bo'lsa agent ishga tushmaydi va ro'yxatni o'zi ko'rsatadi.

### 7-qadam. Qo'lda sinab ko'ring

1. Agentni ishga tushiring:

   ```bat
   .venv\Scripts\python agent.py
   ```

   Oynada **«obuna tasdiqlandi»** yozuvi chiqishi kerak.
2. Dasturchi serverdan sinov chekini yuboradi:

   ```bash
   docker compose -f docker-compose.prod.yml exec app php artisan print-agent:test-receipt "Guzar Kafe"
   ```

3. Printerdan **sinov cheki** chiqishi kerak. Tekshiring:
   - tepadagi `1234567890…` chizig'i qog'ozga **to'liq sig'adimi** (chetidan kesilmaganmi);
   - kirill va maxsus belgilar lotin harfida, o'qiladigan bo'lib chiqdimi.
4. Chek kengligini **admin panelda** moslang: **/admin → Restoranlar → Guzar Kafe → POS → «Chek qog'ozi»**:
   - **80 mm (48 belgi)** — XP-80T uchun eng to'liq variant (576 nuqta);
   - cheti kesilsa yoki qatorlar buzilsa **80 mm (42 belgi)** ga qaytaring.
5. To'xtatish: oynada `Ctrl + C`.

### 8-qadam. Kompyuter yonganda o'zi ishga tushsin (NSSM servis)

NSSM agentni Windows xizmatiga aylantiradi: kompyuter qayta yoqilganda agent **o'zi** ishga tushadi, xato bilan to'xtasa
qayta ishga tushiradi, hech kim tizimga kirishi shart emas.

1. [nssm.cc/download](https://nssm.cc/download) dan NSSM'ni yuklab, arxivdan chiqaring, masalan `C:\nssm\win64\nssm.exe`.
2. **Administrator** sifatida `cmd` oching (*Boshlash → cmd → o'ng tugma → Administrator sifatida ishga tushirish*) va bajaring:

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

   `AppDirectory` muhim: `.env`, `queue.db` va `agent.log` shu papkadan o'qiladi va yoziladi.

3. **Tekshirish:**
   - `C:\nssm\win64\nssm.exe status YetkazPrintAgent` → `SERVICE_RUNNING` chiqsin;
   - `C:\yetkaz\print-agent\agent.log` oxirida «obuna tasdiqlandi» bo'lsin;
   - **kompyuterni qayta yoqing** va agent o'zi ko'tarilganini (yana `status` va sinov cheki bilan) tekshiring.

Boshqarish (Administrator `cmd`'da):

```bat
nssm restart YetkazPrintAgent
nssm stop YetkazPrintAgent
nssm edit YetkazPrintAgent          :: oyna orqali sozlamalar
nssm remove YetkazPrintAgent confirm
```

> **Muhim:** servisni o'rnatishdan **oldin** agentni qo'lda ishga tushirib (7-qadam) ishlashini tekshiring. Servis
> orqali ishlamasa avval `agent.log`, keyin `service.log` ga qarang.

### 9-qadam. Yangilash tartibi

Agentning yangi versiyasi chiqqanda (Administrator `cmd`'da):

```bat
nssm stop YetkazPrintAgent
```

1. Eski `agent.py` va boshqa dastur fayllarini yangisi bilan almashtiring.
   **`.env`, `queue.db` va `agent.log` ni o'chirmang** (navbatdagi cheklar `queue.db` da).
2. Kutubxonalarni yangilang va servisni ishga tushiring:

   ```bat
   cd C:\yetkaz\print-agent
   .venv\Scripts\pip install -r requirements.txt
   nssm start YetkazPrintAgent
   ```

3. `agent.log` da «obuna tasdiqlandi» ni va sinov chekini tekshiring.

### Muammolar va ularning sababi

| Belgi | Sabab va nima qilish kerak |
|---|---|
| Agent ishga tushmaydi, «PRINTER_NAME .env da bo'sh» | `.env` ga printer nomini yozing (`--list-printers` bilan aniqlang). |
| «nomli printer topilmadi» | Nom xato yozilgan. Ro'yxatdagi nomni aynan nusxalang. |
| `agent.log`: «printer tayyor emas: oflayn …» | Printer o'chiq yoki USB kabel uzilgan. Yoqing/ulang — chek **navbatda turadi** va printer tayyor bo'lgach **o'zi chiqadi**, yo'qolmaydi. |
| «oflayn: «Use Printer Offline» yoqilgan» | Windows'da printer oflayn rejimga qo'yilgan: *Printerlar → printer → Chop etish navbatini ko'rish → Printer menyusi → «Printerni oflayn ishlatish»* belgisini olib tashlang. |
| «printer ochilmadi» / «spooler xatosi» | Windows chop etish xizmati (*Print Spooler*) ishlamayapti yoki drayver buzilgan. Kompyuterni qayta yoqing, drayverni qayta o'rnating. |
| Chek chiqmaydi, xato ham yo'q | Windows'dan *Sinov sahifasi* chiqadimi tekshiring (2-qadam). Chiqmasa — drayver/USB muammosi. |
| Harflar «iflos» chiqyapti | Sinov chekini `--table` bilan yuboring (`print-agent:test-receipt "Guzar Kafe" --table`) va admin'da «Kodlash jadvali» ni o'zgartiring. |

**Cheklov (bilib qo'ying).** Windows'ning o'zi printerning *qog'oz tugagan* yoki *qopqoq ochiq* holatini har doim bilmaydi
(ko'p USB termoprinterlar buni kompyuterga bildirmaydi). Shunda agent chekni printerga muvaffaqiyatli yuborilgan deb
hisoblaydi, chek esa chiqmasligi mumkin. Shuning uchun printerda qog'oz borligini vaqti-vaqti bilan ko'zdan kechiring.
Printer **o'chiq yoki USB uzilgan** bo'lsa esa agent buni biladi va chekni navbatda saqlaydi.

## Umumiy o'rnatish (Linux yoki LAN printer)

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
#    PRINTER_MODE=simulate            # sinov; keyin: network (LAN) | windows (USB, Windows) | usb-linux

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
| `network` | LAN printer: ESC/POS baytlarini `PRINTER_HOST:PRINTER_PORT` (odatda `:9100`) ga yuboradi. |
| `windows` | **USB printer (Windows).** Baytlarni Windows chop etish tizimiga (spooler) **RAW** rejimda yozadi (`win32print`). `PRINTER_NAME` kerak (`python agent.py --list-printers`). |
| `usb-linux` | USB printer (Linux): baytlarni `PRINTER_DEVICE` (standart `/dev/usb/lp0`) fayliga yozadi. Foydalanuvchi `lp` guruhida bo'lishi (yoki fayl ruxsati) kerak. |

`windows` rejimida: printer **o'chiq, oflayn yoki USB uzilgan** bo'lsa (yoki spooler xato bersa) buyurtma **navbatda qoladi**
va qayta uriniladi — yagona chop etuvchi, tartib va tasdiq (ack) qoidalari `network` bilan bir xil. Windows'ga chop
etilmagan ishlar to'planib qolmasligi uchun agent printer holatini yozishdan **oldin** tekshiradi. Yordamchi buyruq:
`python agent.py --list-printers` (o'rnatilgan printerlar nomlari; token kerak emas).

## Chek formati va sinov cheki

Chek **backend'da** tuziladi (agent faqat baytlarni printerga uzatadi). Sozlamalar —
`/admin` → Restoranlar → restoran → «POS» (pos_type = escpos bo'lganda):

| Sozlama | Qiymat |
|---|---|
| Chek qog'ozi | **58 mm** (32 belgi), **80 mm** (42 belgi, standart) yoki **80 mm (48 belgi)** — Xprinter XP-80T Font A'da 576 nuqta = 48 belgi. Mavjud restoranlar o'zgarmaydi |
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

### Windows

Windows kompyuterida o'rnatish, `.env` sozlash, printerni sinash va **NSSM bilan servis qilish** (kompyuter
qayta yonganda agent o'zi ishga tushishi) — yuqoridagi
[«Windows kompyuterida o'rnatish»](#windows-kompyuterida-ornatish--usb-printer-xprinter-xp-80t) bo'limida qadam-baqadam.

### Linux

`systemd` unit (`Restart=always`, `WorkingDirectory=<agent papkasi>`,
`ExecStart=<papka>/.venv/bin/python agent.py`) yoki `screen`/`tmux`. Agent o'zi uzilishda qayta ulanadi.

## Testlar

```bash
pip install -r requirements.txt -r requirements-dev.txt
python -m pytest
```

Testlar vaqtinchalik papkada ishlaydi (haqiqiy `.env`, `queue.db`, `agent.log` ga tegmaydi).
