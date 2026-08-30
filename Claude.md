# Yetkaz — Telegram orqali ovqat yetkazib berish platformasi

## Loyiha haqida

Telegram bot + Mini App orqali foydalanuvchi o'z shahridagi restoran va fast-foodlardan
ovqat buyurtma qiladi. Buyurtma avtomat ravishda oshxonaga tushadi, chek printerdan
chiqadi, mijozga esa yetkazib berish vaqti hisoblanib yuboriladi.

Tizim uch tomonga xizmat qiladi:

1. **Mijoz** — Telegram bot va Mini App
2. **Oshxona** — real-time admin panel + chek printeri
3. **Platforma admini** — restoranlar, menyular, hisobotlar

---

## Texnologiyalar

| Qism | Texnologiya |
|---|---|
| Framework | Laravel 12 + Octane (RoadRunner) |
| Telegram bot | Nutgram (webhook rejimi, long polling emas) |
| WebSocket | Laravel Reverb |
| Navbat | Laravel Horizon + Redis |
| Admin panel | Filament 3 |
| Baza | PostgreSQL 16 + PostGIS |
| Kesh / savat | Redis |
| Mini App | React + Vite + Telegram WebApp SDK + Yandex Maps JS API |
| Marshrut / masofa | OSRM (self-hosted), zaxira sifatida Haversine |
| Print agent | **Python** (alohida servis, `python-escpos`) |
| Deployment | Docker Compose, Ubuntu server |

Print agent — yagona PHP bo'lmagan qism. U oshxonadagi kompyuterda ishlaydi va
backend bilan faqat WebSocket orqali gaplashadi. Uni asosiy repo ichida
`print-agent/` papkasida saqlaymiz.

---

## Papka tuzilishi

```
app/
  Http/Controllers/Api/        # Mini App uchun REST API
  Telegram/                    # Nutgram handlerlar, middleware, keyboardlar
  Services/
    Order/                     # Buyurtma yaratish, status boshqaruvi
    Eta/                       # Yetkazib berish vaqtini hisoblash
    Routing/                   # OSRM klienti, masofa hisoblash
    Dispatch/                  # Buyurtmani oshxonaga yuborish (POS yoki agent)
      Drivers/                 # JowiDriver, PosterDriver, IikoDriver, EscPosDriver
  Models/
  Filament/                    # Admin va oshxona panellari
  Jobs/                        # Fon vazifalari
  Events/                      # WebSocket eventlari
database/migrations/
resources/js/miniapp/          # React Mini App
print-agent/                   # Python ESC/POS agenti
docker/
```

---

## Baza sxemasi

```
users
  id, telegram_id (unique), full_name, phone, language,
  profile_completed (bool), created_at

addresses
  id, user_id, label, lat, lng, address_text,
  entrance, floor, apartment, note, is_default

cities
  id, name, center_lat, center_lng

restaurants
  id, name, city_id, lat, lng, phone, logo_url,
  avg_prep_time_min, delivery_radius_km, min_order_amount,
  delivery_fee, is_open, work_hours (jsonb),
  pos_type (enum: jowi|poster|iiko|escpos|manual),
  pos_credentials (jsonb, encrypted)

categories
  id, restaurant_id, name, sort_order, is_active

products
  id, category_id, name, description, price, photo_url,
  prep_time_min, is_available, sort_order

orders
  id, order_number, user_id, restaurant_id, address_id,
  items (jsonb), subtotal, delivery_fee, total,
  payment_method, payment_status, status,
  eta_minutes, distance_km,
  dispatched_at, printed_at, delivered_at, created_at

order_status_history
  id, order_id, status, changed_at, changed_by
```

Buyurtma `items` jsonb'da saqlanadi (nom, narx, miqdor snapshot bilan) — menyu
keyin o'zgarsa ham eski buyurtma buzilmaydi.

---

## Biznes qoidalari

Bu qoidalarni buzmang, ular loyihaning asosi.

### Foydalanuvchi ma'lumotlari

- Telefon raqami **faqat** `request_contact` tugmasi orqali olinadi, hech qachon
  qo'lda yozdirilmaydi.
- Ism, telefon va lokatsiya **bir marta** so'raladi. `profile_completed = true`
  bo'lgach, boshqa hech qachon so'ralmaydi.
- Profilni o'zgartirish faqat "Sozlamalar" bo'limida bo'ladi.
- Foydalanuvchi bir nechta manzil saqlashi mumkin ("Uy", "Ish"), biri `is_default`.

### Savat va restoran almashtirish

- Savat **restoran bo'yicha alohida** saqlanadi. Foydalanuvchi restoran A'dan
  chiqib B'ga kirsa, A'dagi savat o'chirilmaydi.
- Restorandan chiqishda savatni tozalamang, faqat "Savatingiz saqlanadi" deb
  bildiring.
- Buyurtma faqat bitta restorandan bo'ladi — aralash savat yo'q.

### Umumiy taom qidiruvi

Foydalanuvchi taom nomini yozsa (masalan "lag'mon"), tizim yetkazish radiusidagi
**barcha** restoranlarni qidirib, o'sha taom bor joylarni narxi va ETA'si bilan
ko'rsatadi. Bu restorandan restoranga kirib-chiqishga muqobil, asosiy UX yo'li.
PostgreSQL `pg_trgm` indeksidan foydalaning.

### Restoranlarni filtrlash

Foydalanuvchiga faqat quyidagi shartlarni qanoatlantiradigan restoranlar
ko'rsatiladi:

- `is_open = true` va joriy vaqt `work_hours` ichida
- Masofa `delivery_radius_km` dan kichik

Radiusdan tashqaridagi restoran ro'yxatda umuman ko'rinmaydi — buyurtma bosqichida
"yetkazib bera olmaymiz" deyish kech.

### ETA hisoblash

```
ETA = pishirish + navbat_jarimasi + kuryer_kutish + yol_vaqti + bufer

pishirish       = max(savatdagi taomlarning prep_time_min)   # parallel, ketma-ket emas
navbat_jarimasi = min(restorandagi_faol_buyurtmalar * 2, 20)
kuryer_kutish   = 5
yol_vaqti       = (masofa_km / tezlik) * 60
                  tezlik = 22 km/soat (07:30-10:00 va 17:00-20:00), aks holda 28
bufer           = 5
```

- Masofa OSRM'dan olinadi. OSRM ishlamasa Haversine * 1.35 zaxira sifatida.
- Bir xil restoran-manzil juftligi uchun natija Redis'da 1 soat keshlanadi.
- Mijozga **aniq raqam emas, oraliq** yuboriladi: 42 chiqsa "35-50 daqiqa".
- Har buyurtmada `distance_km` va haqiqiy `delivered_at` saqlanadi — keyinchalik
  koeffitsientlarni shu ma'lumot asosida to'g'rilaymiz.

### Buyurtmani oshxonaga yuborish

`OrderDispatcher` restoranning `pos_type` iga qarab drayver tanlaydi. Barcha
drayverlar bitta interfeysni amalga oshiradi:

```php
interface DispatchDriver {
    public function dispatch(Order $order): DispatchResult;
}
```

- `jowi`, `poster`, `iiko` — POS API orqali. POS o'zi chek chiqaradi.
- `escpos` — print agentga WebSocket orqali yuboriladi.
- `manual` — faqat oshxona panelida ko'rinadi, chek yo'q.

**Muhim:** yuborish muvaffaqiyatsiz bo'lsa buyurtma yo'qolmasligi kerak.
Retry bilan job'ga qo'ying (3 urinish, exponential backoff), muvaffaqiyatsiz
bo'lsa oshxona paneli va platforma adminiga ogohlantirish chiqaring.

### Print agent

- Oshxonadagi kompyuterda ishlaydi, backend'ga WebSocket bilan ulanadi.
- Buyurtma kelganda ESC/POS ga o'girib `192.168.x.x:9100` ga yuboradi.
- **Lokal navbat majburiy:** internet uzilsa yoki printer o'chgan bo'lsa,
  buyurtma SQLite'ga yoziladi va ulanish tiklanganda bosiladi.
- Bosilgach backend'ga tasdiq yuboradi, `orders.printed_at` to'ldiriladi.

### Oshxona paneli

Planshetda ochiq turadigan veb-sahifa (Telegram emas). Ko'rsatiladi:

- Buyurtma raqami, kelgan vaqti, o'tgan daqiqalar sanog'i
- Mijoz ismi va telefoni (bosilsa qo'ng'iroq)
- Manzil matni + kichik xaritada nuqta
- Taomlar va izohlar
- Status tugmalari: qabul qilindi -> tayyorlanmoqda -> yo'lga chiqdi -> yetkazildi

Yangi buyurtma Reverb orqali darhol chiqadi va ovozli signal beradi.
Har status o'zgarishi mijozga bot orqali avtomat xabar yuboradi.

---

## Kod uslubi

- Barcha biznes mantiq Service klasslarida, Controller va Telegram handlerlar ingichka.
- Tashqi API chaqiruvlari (Telegram, OSRM, POS) doim job orqali, HTTP so'rov ichida emas.
- Baza so'rovlarida N+1 dan qoching — menyu yuklashda `with()` ishlating.
- Migratsiyalarda foreign key va indekslar aniq ko'rsatilsin.
- Pul `integer` (tiyinda) saqlanadi, `float` emas.
- Vaqtlar UTC'da saqlanadi, ko'rsatishda `Asia/Tashkent` ga o'giriladi.
- Interfeys matnlari o'zbek va rus tilida (`lang/uz`, `lang/ru`), kod ichida
  qattiq yozilmaydi.
- Har yangi Service uchun feature test yozing.

---

## Ishlab chiqish bosqichlari

1. Baza migratsiyalari + modellar
2. Bot: ro'yxatdan o'tish oqimi (ism, telefon, lokatsiya) va manzil saqlash
3. Mini App: restoranlar ro'yxati, menyu, savat
4. Buyurtma yaratish + oshxona paneli (chek yo'q, faqat ekranda)
5. ETA hisoblash (avval Haversine, keyin OSRM)
6. Print agent + ESC/POS drayveri
7. POS integratsiyalari (Poster'dan boshlang — ochiq API bor)
8. To'lov: Payme va Click
9. Filament admin: restoran va menyu boshqaruvi, hisobotlar

Har bosqich yakunida ishlaydigan mahsulot bo'lsin. 4-bosqichdan keyin bitta real
restoran bilan sinov boshlash mumkin.

---

## Ochiq savollar

- Jowi API'ga kirish uchun ular bilan hamkorlik shartnomasi kerak — ochiq hujjat
  yo'q. Ishni boshlashdan oldin aniqlashtirilsin.
- Kuryer boshqaruvi hozircha loyihada yo'q. Keyingi bosqichda kuryer uchun alohida
  bot qo'shiladi.