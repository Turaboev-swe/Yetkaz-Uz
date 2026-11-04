# Mahsulot qoldig'i (stock)

Restoran mahsulotga dona sonini kiritadi. Buyurtma berilganda kamayadi, bekor qilinganda qaytadi, 0 bo'lganda mijozga
«Tugadi» ko'rinadi, mijoz Mini App'da «Faqat N ta qoldi!» ni ko'radi.

## Qoldiq qanday ishlaydi

| `products.stock_quantity` | Ma'nosi |
|---|---|
| `NULL` | **Cheklanmagan** (hozirgi xatti-harakat; barcha mavjud mahsulotlar shunday qoladi) |
| `0` | **Tugadi** — Mini App'da kulrang, savatga qo'shib bo'lmaydi |
| `N > 0` | Shuncha dona; buyurtma kamaytiradi |

- **«Tugadi» qoldiqdan hisoblanadi**, `is_available` ga tegilmaydi. `is_available = false` — restoranning qo'lda
  yashirishi (mijozga umuman ko'rinmaydi); qoldiq 0 bo'lsa mahsulot menyuda **kulrang bo'lib turadi**. Restoran sonni
  oshirsa mahsulot o'zi qaytadi.
- Umumiy qidiruvda tugagan taom **chiqmaydi** (maqsad — «qayerdan buyurtma qilsam bo'ladi»).
- «Faqat N ta qoldi!»: qoldiq `config('yetkaz.stock_display_threshold')` ga (standart **5**) teng yoki kam bo'lganda.
  `.env`: `STOCK_DISPLAY_THRESHOLD=5`; `null` — cheklangan qoldig'i bor har bir mahsulotda doim ko'rsatiladi.

### Buyurtma berilganda (atomik)
`OrderService::place()` → `StockService::reserve()` **buyurtma tranzaksiyasi ichida**:
`UPDATE products SET stock_quantity = stock_quantity - :qty WHERE id = :id AND stock_quantity >= :qty`.
Yetmasa `422`: «Kechirasiz, **Osh** dan faqat 2 ta qoldi.» (0 bo'lsa «Kechirasiz, Osh tugagan.») — buyurtma ham, promo ham
rollback bo'ladi. Mahsulotlar id tartibida qulflanadi (deadlock yo'q). Bazada `CHECK (stock_quantity >= 0)` ham bor.
Frontendga ishonilmaydi: Mini App cheklasa ham, haqiqiy tekshiruv shu yerda.

### Bekor qilinganda (idempotent)
`orders.stock_reserved` ga buyurtma **haqiqatan kamaytirgan** miqdorlar yoziladi (`{product_id: qty}`, cheksiz mahsulot
kirmaydi). `StockService::release()` faqat shu xaritadagini qaytaradi va xaritadan **ayiradi** — ikkinchi chaqiruvda
qaytariladigan narsa qolmaydi (buyurtma qatori `lockForUpdate` bilan qulflanadi). Chaqiriladigan yagona nuqta:
`OrderStatusService::applyTransition()` (`OrderStatus::releasesStock()`, hozir faqat `cancelled`) — oshxona paneli
ham, Telegram oshxona boti ham shu yerdan o'tadi. Qoldiq qaytmasa bekor qilish ham bo'lmaydi (bitta tranzaksiya).
- Mahsulot keyin cheksiz (`NULL`) qilingan bo'lsa — tegilmaydi.
- Buyurtma oxirgi **kunlik tiklanish**dan oldin berilgan bo'lsa — qaytarilmaydi (kechagi buyurtma bugungi qoldiqni oshirmasin).
- Qisman qaytarish tayyor: `release($order, [product_id => qty])` (kelajakdagi «qisman buyurtma» uchun). Qisman buyurtmaning
  o'zi (oshxonada qatorni «yo'q» deb belgilash) hali **qurilmagan**.

### Kunlik qoldiq
Mahsulotda `daily_stock` + `daily_reset_time` (Asia/Tashkent): `stock:reset-daily` (scheduler, **har daqiqa**) vaqti
kelganda `stock_quantity = daily_stock` qiladi. **Kuniga bir marta**: `stock_reset_at` bugungi lahzadan oldin bo'lsa
tiklanadi; scheduler daqiqani o'tkazib yuborsa shu kun ichida keyingi daqiqada tiklanadi. Sozlama saqlanganda
`stock_reset_at = hozir` — egasi hozir kiritgan qoldiq darhol qayta yozilib ketmaydi (keyingi tiklash ertaga).

### Restoran paneli (`/restaurant → Mahsulotlar`)
Formada «Qoldiq (dona)», «Kunlik qoldiq», «Qayta tiklanish vaqti». Ro'yxatda qoldiqni **inline tahrirlash**
(bo'sh = cheklanmagan ∞, `0` = Tugadi), «Tugagan» filtri, jadval 15 s da o'zi yangilanadi.

### Real-time
Qoldiq o'zgarganda (buyurtma, bekor qilish, kunlik tiklanish, restoran tahriri) **Reverb hodisasi** yuboriladi:
ochiq kanal `menu.{restaurantId}`, hodisa `product.stock`, ichida `products: [{id, stock, sold_out, low_stock}]`;
tranzaksiya commit bo'lgandan keyin, rollbackda yuborilmaydi.
- **Restoran paneli** (Filament'da Echo yo'q): jadval polling (15 s).
- **Mini App Reverb'ga ulanmagan.** U qoldiqni menyu ochilganda, ilovaga qaytilganda (`visibilitychange`), savat va
  rasmiylashtirish ochilganda yangilaydi; checkout'da server baribir qayta tekshiradi. Jonli yangilash keyingi
  bosqich: `menu.{id}` ga `laravel-echo` bilan obuna bo'lish kifoya (kanal ochiq, qo'shimcha avtorizatsiya kerak emas).

## Ma'lum cheklovlar (alohida muammolar)
1. **Qabul qilinmagan (`new`) buyurtma abadiy pending bo'lib qolishi mumkin** — uni rad etib ham, bekor qilib ham
   bo'lmaydi (`CANCELLABLE = accepted | preparing`), avtomatik yopilmaydi (faqat push takrori va 7 daqiqada admin
   ogohlantirishi). Uning qoldig'i ham band bo'lib qoladi. Hal qilish: «rad etish» yoki muddati o'tganda avtomatik
   `cancelled` — u `OrderStatusService` orqali o'tsa qoldiq o'zi qaytadi.
2. `on_the_way` dan bekor qilib bo'lmaydi (faqat `delivered`).
3. Qisman buyurtma qurilmagan (yuqoriga qarang).

## Deploy (serverda)
```bash
cd /opt/yetkaz

# 1) MIGRATSIYADAN OLDIN backup (pg_dump). Kunlik backup.sh ning nomi sana bilan — alohida fayl oling:
mkdir -p backups
docker compose -f docker-compose.prod.yml exec -T postgres \
  pg_dump -U yetkaz -d yetkaz --no-owner --no-privileges | gzip -9 > backups/pre-stock-$(date +%F-%H%M).sql.gz
ls -la backups/pre-stock-*.sql.gz          # bo'sh/juda kichik bo'lmasin (kamida bir necha yuz KB)

# 2) Kod va build
git pull origin main
docker compose -f docker-compose.prod.yml build app
docker compose -f docker-compose.prod.yml up -d      # app migrate --force ni o'zi bajaradi
```
Migratsiya `2026_11_09_000001` faqat **nullable ustunlar** va CHECK/indeks qo'shadi (`products`: `stock_quantity`,
`daily_stock`, `daily_reset_time`, `stock_reset_at`; `orders`: `stock_reserved`, `stock_released_at`) — mavjud
ma'lumot o'zgarmaydi, qayta yozish yo'q. Tiklash: `docs/deploy.md` «Backup va tiklash».

Tekshirish:
```bash
docker compose -f docker-compose.prod.yml exec app php artisan migrate:status | tail -3
docker compose -f docker-compose.prod.yml exec app php artisan stock:reset-daily       # xato bermasligi kerak (jim)
docker compose -f docker-compose.prod.yml exec app php artisan schedule:list | grep stock
```
Orqaga qaytarish: `php artisan migrate:rollback --step=1` ustunlarni olib tashlaydi (qoldiq ma'lumotlari yo'qoladi).
