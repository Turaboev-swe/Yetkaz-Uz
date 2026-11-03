# Brauzer orqali chek chiqarish (`pos_type = browser`)

Oshxona kompyuteriga **hech narsa o'rnatmasdan** — faqat brauzer (Edge yoki Chrome) bilan: oshxona panelida
(`/kitchen`) **«Qabul qilish»** bosilganda chek Windows printeridan (masalan, kassa printeri) o'zi chiqadi.
Printer oshxonaning kassa dasturi (AliPOS) bilan umumiy bo'lishi mumkin.

Print agent (`print-agent/`) bu rejimda **kerak emas**. Agent bilan ishlaydigan `escpos` rejimi o'zgarmagan.

## Qanday ishlaydi

1. Xodim panelda **«Qabul qilish»** bosadi. Server buyurtmani qabul qiladi.
2. Qabul muvaffaqiyatli bo'lgach panel yashirin iframe'da chek sahifasini (`/kitchen/orders/{id}/receipt`) ochadi,
   logo yuklangach `window.print()` chaqiradi.
3. Yorliqdagi `--kiosk-printing` tufayli dialog chiqmaydi — chek oxirgi tanlangan printerga darhol ketadi.
4. Chop etish chaqirilgach `orders.printed_at` yoziladi.

Chek ma'lumoti ESC/POS cheki bilan **bir manbadan** (`ReceiptFormatter::data()`) olinadi: raqam, vaqt, tur, taomlar,
izoh, mijoz, telefon(lar), manzil, chegirma, to'lov usuli, mijozdan olinadigan summa. Maket HTML/CSS: tepada Yetkaz
logosi va **YETKAZ UZ**, restoran nomi; pastda «Yetkaz Uz orqali buyurtma» va botga QR-kod (serverda SVG qilib
yaratiladi, tashqi servis yo'q).

Kartada **«Chekni chiqarish» / «Chekni qayta chiqarish»** tugmasi bor (faqat brauzer rejimidagi restoranlarda).

## 1. Admin panelda yoqish (dasturchi/admin)

`/admin → Restoranlar → <restoran> → POS`:

- **POS turi:** `Brauzer orqali chop etish`
- **Chek qog'ozi:** `80 mm` (yoki `58 mm`) — chek shu kenglikda chiqadi (80 mm → bosiladigan 72 mm, 58 mm → 48 mm).

Saqlang. Eski agent (agar qolgan bo'lsa) bu restoranda baribir jim: heartbeat yozilmaydi, `pending` bo'sh qaytadi,
ikkinchi nusxa chiqmaydi.

## 2. Oshxona kompyuterida (Windows 10)

### 2.1. «Kassa» printerini standart qiling

*Sozlamalar → Qurilmalar → Printerlar va skanerlar → kassa printeri → Boshqarish → Standart qilish.*
Brauzer saqlangan tanlovni yo'qotsa ham chek boshqa printerga ketmasin.

Agar printer AliPOS bilan **Windows drayveri orqali** ulangan bo'lsa, ikkala dastur ham bitta Windows chop etish
navbatiga yozadi — ishlar navbat bilan chiqadi. Drayver sozlamasida qog'oz turi **roll (80 mm)** va «hujjat tugagach
qirqish» yoqilgan bo'lsin.

### 2.2. Bir martalik sozlash: Ctrl+P (yorliqsiz, oddiy brauzerda)

`--kiosk-printing` oxirgi tanlangan printer va sozlamalardan foydalanadi, shuning uchun ularni **bir marta**
dialog bilan belgilab, chop etib qo'yish shart. Buni yorliqdan **emas**, brauzerni Boshlash menyusidan oddiy
ochib qiling (yorliqda `--kiosk-printing` dialogni ko'rsatmaydi):

1. Edge yoki Chrome'ni oddiy oching, `https://yetqaz.uz/kitchen` ga kiring.
2. Biror buyurtma kartasida **«Chekni chiqarish»** ni bosing (buyurtma yo'q bo'lsa — `/admin → Buyurtmalar` dan
   buyurtma raqamini olib `https://yetqaz.uz/kitchen/orders/<ID>/receipt` ni oching va `Ctrl+P` bosing).
3. Chop etish oynasida:
   - **Printer:** «kassa» printerini tanlang;
   - **Qog'oz o'lchami (Paper size):** 80 mm roll (printer drayveridagi 80 mm variant);
   - **Maydonlar (Margins / Поля):** **Yo'q (None)**;
   - **Masshtab (Scale):** 100%;
   - **Kolontitullar (Headers and footers):** o'chiq.
4. **Chop etish** ni bosing va chek chiqqanini ko'ring.
5. Brauzerni to'liq yoping.

> **Edge va Chrome sozlamalari alohida saqlanadi.** Qaysi brauzerning yorlig'i ishlatilsa, 2.2 ni shu brauzerda
> bajaring.

### 2.3. Yorliqlar

Ish stolida o'ng tugma → *Yaratish → Yorliq* → «Obyekt joylashuvi»:

**Edge:**
```
"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe" --app=https://yetqaz.uz/kitchen --kiosk-printing --autoplay-policy=no-user-gesture-required
```

**Chrome:**
```
"C:\Program Files\Google\Chrome\Application\chrome.exe" --app=https://yetqaz.uz/kitchen --kiosk-printing --autoplay-policy=no-user-gesture-required
```

- Chrome 32-bitli bo'lsa yo'l `C:\Program Files (x86)\Google\Chrome\Application\chrome.exe`. Aniq yo'lni brauzer
  yorlig'iga o'ng tugma → *Xususiyatlar* → «Obyekt» dan nusxalang.
- `--kiosk-printing` — chop etishda dialogsiz, oxirgi printerga. **Busiz har chekda dialog chiqadi.**
- `--app=` — manzil satrisiz alohida oyna.
- `--autoplay-policy=…` — yangi buyurtma ovozli signali bloklanmasin. Birinchi kirishda panelga bir marta bosing.
- Avtoyuklash: yorliqni `Win + R` → `shell:startup` papkasiga nusxalang.

### 2.4. «Fon ilovalari»ni o'chiring

Brauzer oynasi yopilganda jarayoni fonda qolsa, keyingi yorliq **mavjud jarayonga** ulanadi va `--kiosk-printing`
**qo'llanmaydi** (bayroqlar faqat yangi jarayonga ta'sir qiladi) — dialog qayta chiqa boshlaydi.

- **Edge:** `edge://settings/system` → *«Microsoft Edge yopilganda fon kengaytmalari va ilovalarini ishlatishni
  davom ettirish»* → **O'chiq**; *Startup boost* → **O'chiq**.
- **Chrome:** `chrome://settings/system` → *«Google Chrome yopilganda fon ilovalarini ishlatishni davom ettirish»*
  → **O'chiq**.
- Windows 10 (ixtiyoriy): *Sozlamalar → Maxfiylik → Fon ilovalari* da Edge/Chrome ni o'chiring.

Sozlamadan keyin brauzerni to'liq yoping va yorliq orqali qayta oching. Tekshirish: `Ctrl+Shift+Esc` →
`msedge.exe` / `chrome.exe` oyna yopilgach qolmasligi kerak.

### 2.5. Tekshirish

1. Yorliq orqali panelni oching, test buyurtmada **«Qabul qilish»** bosing → chek dialogsiz chiqishi kerak.
2. Chekda: logo, **YETKAZ UZ**, restoran nomi, buyurtma raqami (katta), taomlar, **MIJOZDAN OLINADI** (katta),
   pastda QR va `@Yetkaz_uzbot`. QR'ni telefon bilan skanerlab botga o'tishini tekshiring.
3. Chek chetdan kesilsa — 2.2 dagi *Maydonlar: Yo'q* va qog'oz o'lchamini tekshiring.

## 3. Logo va bot

- Logo: `public/images/yetkaz-logo.png` (hozircha). Almashtirish: faylni shu nom bilan almashtiring **yoki** yangi
  faylni `public/images/` ga qo'ying va `.env` ga `BROWSER_RECEIPT_LOGO=images/<fayl>.png` yozing. Eni ~40 mm.
  Termoprinterda qora-oq chiqadi (CSS filtri `grayscale(100%) contrast(180%)`), shuning uchun kontrastli,
  oddiy rasm yaxshi.
- QR/yozuvdagi bot: standart `Yetkaz_uzbot`. Boshqa bot uchun `.env`: `BROWSER_RECEIPT_BOT=<username>`.
- Ikkalasi o'zgargach: `php artisan octane:reload` (config kesh bo'lsa `config:cache` avtomat qayta quriladi).

## 4. Oldindan ko'rish

`https://yetqaz.uz/kitchen/orders/<ID>/receipt` — chek sahifasi, hech narsa yozmaydi va chop etmaydi (sahifada
«Chop etish» tugmasi bor). Faqat shu restoran xodimlariga ochiladi: egasi boshqa restoran buyurtmasiga — 404,
oshxona xodimi — 403, kirmagan foydalanuvchi — `/kitchen/login`.

## 5. Cheklovlar (bilib qo'ying)

- **Chek faqat oshxona paneli ochiq turgan kompyuterda, «Qabul qilish» shu panelda bosilganda chiqadi.** Buyurtma
  Telegram oshxona boti orqali qabul qilinsa yoki panel yopiq bo'lsa chek chiqmaydi — kartadagi **«Chekni
  chiqarish»** tugmasidan foydalaning. Agentdagidek lokal navbat (SQLite) yo'q.
- **Monitoring yo'q:** «Printer agenti o'chiq» banneri, «Chek chiqmadi» belgisi va heartbeat bu rejimda ishlamaydi.
  `printed_at` — «chop etish **chaqirildi**» degani; qog'ozga chiqqanini kafolatlamaydi (printer o'chiq bo'lsa
  chek Windows navbatida kutadi, qog'oz tugagan bo'lsa Windows bilmasligi mumkin). Printerni vaqti-vaqti bilan
  ko'zdan kechiring.
- **`@page { size: 80mm auto }` ni Chrome/Edge rad etadi** (tekshirilgan: balandlik `auto` bo'lsa qog'oz o'lchami
  printerning o'zidan olinadi). Shuning uchun 2.2 da printer drayveridagi **80 mm roll** o'lchamini tanlash shart.
  Bosiladigan kenglik (72/48 mm) CSS'da aniq berilgan.
- Chop etish xatosi (masalan, sessiya tugagan) panelda qizil xabar sifatida chiqadi, buyurtma holati o'zgarmaydi.

## 6. Muammolar

| Belgi | Sabab va yechim |
|---|---|
| Har chekda dialog chiqadi | Yorliqda `--kiosk-printing` yo'q **yoki** brauzer fonda ishlab turgan edi (2.4) **yoki** panel yorliq orqali emas, oddiy brauzerda ochilgan. Brauzerni to'liq yoping, yorliq orqali oching. |
| Chek boshqa printerga ketdi | 2.2 da kassa printeri tanlanmagan; kassa printerini Windows'da standart qiling (2.1). |
| Chekning chetlari kesilgan | *Maydonlar: Yo'q*, masshtab 100%; admin'da qog'oz kengligi to'g'rimi (58/80). |
| Chek juda uzun/qog'oz bo'sh chiqadi | Paper size 80 mm **roll** emas (A4/Letter tanlangan) — 2.2 ni qaytaring. |
| Logo qora dog' bo'lib chiqdi | Logo faylini kontrastli, oq fonli qora-oq rasm bilan almashtiring (3-bo'lim). |
| «Chek ochilmadi (sessiya tugagan…)» | Panelga qayta kiring (`/kitchen/login`). |
| Chek «Qabul qilish»da chiqmadi | Restoran POS turi `Brauzer orqali chop etish`mi? Brauzer yangi frontend'ni yuklaganmi (`Ctrl+F5`)? |
