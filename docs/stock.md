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
Yetmasa `422`: «Afsus, **Osh** dan faqat 2 ta qoldi.» (0 bo'lsa «Afsus, Osh tugab qoldi.»; ru: «К сожалению, …») —
buyurtma ham, promo ham rollback bo'ladi. Mahsulotlar id tartibida qulflanadi (deadlock yo'q). Bazada `CHECK (stock_quantity >= 0)` ham bor.
Frontendga ishonilmaydi: Mini App cheklasa ham, haqiqiy tekshiruv shu yerda.

**Savatdagi taom keyin yashirilgan bo'lsa** (restoran `is_available = false` qilgan) — u ham shu xabar bilan, NOMI bilan
rad etiladi («Afsus, Osh tugab qoldi.», `code = insufficient_stock`, `shortages[].available = 0`), Mini App savatdan o'sha
qatorni olib tashlaydi; qoldig'i yetmaydigan boshqa qatorlar xuddi shu xabarga qo'shiladi. `POST /api/orders/estimate`
ham yashirilgan taomni `stock_shortages` da (available = 0) oldindan qaytaradi — mijoz «Buyurtma berish»dan oldin ko'radi.
Umuman topilmagan taom (o'chirilgan / boshqa restoran) — nomini aytib bo'lmaydi: «Savatdagi ba'zi taomlar endi mavjud emas».

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

### Oshxona paneli (`/kitchen → Menyu`)
Buyurtmalar yonida «Menyu» tabi: faol kategoriyalar va taomlar, qidiruv, har taom uchun **bir bosishli tugma**
(`PATCH /kitchen/products/{id}/sold-out`, `sold_out: true|false` — toggle emas, aniq holat).
Tugma **qoldiq** bilan ishlaydi, `is_available` ga tegmaydi (Mini App 0 qoldiqni o'zi kulrang «Tugadi» qiladi):

| Tugma | Amal (`StockService::setSoldOut`, bitta shartli UPDATE) |
|---|---|
| **Tugadi** | `stock_quantity = 0` |
| **Bor** | faqat hozir 0 bo'lsa: `stock_quantity = daily_stock` (kunlik qoldiq bo'lmasa `NULL` = cheklanmagan) |

- Yorliq: qoldiq 0 bo'lsa «Tugadi», aks holda «Bor» (+ « · N ta», agar qoldiq cheklangan bo'lsa).
- Eskirgan planshet qoldiqni buzmaydi: qoldiq boshqa joyda allaqachon oshirilgan (> 0) bo'lsa «Bor» bosilishi ustiga yozmaydi;
  ikkinchi «Tugadi» hech narsa o'zgartirmaydi va hodisa yubormaydi.
- `is_available = false` taomlar ro'yxatda «Menyudan yashirilgan (restoran panelida)» belgisi bilan chiqadi — oshxona tugmasi
  ularni mijozga ko'rsatmaydi (bu faqat restoran panelida).
- Restoran izolyatsiyasi: o'z (biriktirilgan) restoranlari; begona restoran menyusi va taomi — **404**. Ro'yxat 20 s da o'zi
  yangilanadi. O'zgarish Reverb `product.stock` hodisasini yuboradi.
- Cheklov: oshxona «Tugadi» (0) qilgandan keyin shu taomning ochiq buyurtmasi bekor qilinsa, qoldiq qaytadi (+qty) va taom
  yana «Bor» bo'lib qoladi — `release()` buyurtma band qilganini qaytaradi. Zarur bo'lsa «Tugadi» ni qayta bosing.

### Real-time
Qoldiq o'zgarganda (buyurtma, bekor qilish, kunlik tiklanish, restoran tahriri) **Reverb hodisasi** yuboriladi:
ochiq kanal `menu.{restaurantId}`, hodisa `product.stock`, ichida `products: [{id, stock, sold_out, low_stock}]`;
tranzaksiya commit bo'lgandan keyin, rollbackda yuborilmaydi.
- **Restoran paneli** (Filament'da Echo yo'q): jadval polling (15 s).
- **Mini App Reverb'ga ulanmagan.** U qoldiqni menyu ochilganda, ilovaga qaytilganda (`visibilitychange`), savat va
  rasmiylashtirish ochilganda yangilaydi; checkout'da server baribir qayta tekshiradi. Jonli yangilash keyingi
  bosqich: `menu.{id}` ga `laravel-echo` bilan obuna bo'lish kifoya (kanal ochiq, qo'shimcha avtorizatsiya kerak emas).

## Bekor qilish yo'llari va poyga himoyasi

`cancelled` ga FAQAT `OrderStatusService::cancel()` olib boradi (`new | accepted | preparing` dan; `on_the_way` va
`delivered` dan emas). Qoldiq qaytishi, mijozga xabar va Reverb hodisasi shu yerdan o'tadi — yo'llar:

| Kim | Qayerda | `changed_by` | Sabab |
|---|---|---|---|
| Restoran | `/kitchen` («❌ Rad etish» — `new`da, «❌ Bekor» — keyin) | `kitchen:{staff_id}` | majburiy |
| Restoran | oshxona boti (`kcancel`) | `kitchen:{staff_id}` | tayyor sabab yoki matn |
| Platforma admini | `/admin → Buyurtmalar` — «Bekor qilish» (ro'yxat qatori va buyurtma sahifasi), faqat `platform_admin` | `admin:{staff_id}` | majburiy, mijozga ko'rsatiladi |

Mijozning o'zi bekor qilishi va avtomatik muddatli bekor qilish (timeout) **qurilmagan** (alohida vazifalar).

**Poyga himoyasi** (`OrderStatusService::applyTransition`): chaqiruvchi o'tishni xotiradagi holat bo'yicha tekshiradi, keyin
tranzaksiya ichida qator `lockForUpdate` bilan qulflanib, bazadagi JORIY holat qayta o'qiladi. U o'zgarib bo'lgan bo'lsa
hech narsa yozilmaydi va «Buyurtma holati allaqachon o'zgargan (hozir: …). Ro'yxatni yangilang.» (422) qaytadi. Natija:
admin «Bekor qilish» va oshxona «Qabul qilish» bir vaqtda bosilsa faqat bittasi o'tadi; bekor qilingan buyurtma keyin
qabul qilinmaydi, qoldiq ikki marta qaytmaydi, ikkinchi planshet buyurtmani ikki qadam o'tkaza olmaydi. Testlar:
`OrderStatusLockTest` (eskirgan nusxa) va `OrderStatusRaceTest` (ikki alohida OS jarayoni, haqiqiy parallel).

## Bekor qilish sababi va tahlil (faqat o'qish)

Saqlanishi: `orders.cancellation_reason` (varchar 500; 2026-09-08 dan oldingi bekorlarda NULL), `orders.cancelled_at` (UTC);
kim va qaysi holatdan — `order_status_history` (`status = 'cancelled'`, `changed_by`, oldingisi — shu buyurtmaning avvalgi
tarix yozuvi). Quyidagi so'rovlar `BEGIN READ ONLY` ichida — yozishga urinish xato beradi. Serverda:

```bash
cd /opt/yetkaz
# so'rovlarni /tmp/cancelled.sql ga qo'ying (quyidagi blok), keyin:
docker compose -f docker-compose.prod.yml exec -T postgres psql -U yetkaz -d yetkaz -v ON_ERROR_STOP=1 < /tmp/cancelled.sql
```

```sql
-- Bekor qilingan buyurtmalar tahlili — FAQAT O'QISH (BEGIN READ ONLY: yozishga urinish xato beradi).
BEGIN READ ONLY;

-- 0) Umumiy: nechta, qachondan qachongacha (UTC), nechtasida sabab yo'q
SELECT COUNT(*)                                                                         AS jami_bekor,
       MIN(cancelled_at)                                                                AS birinchi_utc,
       MAX(cancelled_at)                                                                AS oxirgi_utc,
       COUNT(*) FILTER (WHERE cancellation_reason IS NULL OR btrim(cancellation_reason) = '') AS sababsiz
FROM orders
WHERE status = 'cancelled';

-- 1) Sabab bo'yicha taqsimot (harf registri va bo'shliqlar birlashtirilgan)
SELECT COALESCE(NULLIF(lower(btrim(cancellation_reason)), ''), '(sabab yo''q)') AS sabab,
       COUNT(*)                                                                 AS soni,
       ROUND(100.0 * COUNT(*) / SUM(COUNT(*)) OVER (), 1)                       AS foiz
FROM orders
WHERE status = 'cancelled'
GROUP BY 1
ORDER BY soni DESC, sabab;

-- 2) Restoran bo'yicha: bekor / jami buyurtma va foiz
SELECT r.id,
       r.name,
       COUNT(*) FILTER (WHERE o.status = 'cancelled')                                          AS bekor,
       COUNT(*)                                                                                AS jami,
       ROUND(100.0 * COUNT(*) FILTER (WHERE o.status = 'cancelled') / NULLIF(COUNT(*), 0), 1)  AS bekor_foiz
FROM restaurants r
JOIN orders o ON o.restaurant_id = r.id
GROUP BY r.id, r.name
HAVING COUNT(*) FILTER (WHERE o.status = 'cancelled') > 0
ORDER BY bekor DESC, r.name;

-- 3) Restoran x sabab
SELECT r.name                                                                   AS restoran,
       COALESCE(NULLIF(lower(btrim(o.cancellation_reason)), ''), '(sabab yo''q)') AS sabab,
       COUNT(*)                                                                 AS soni
FROM orders o
JOIN restaurants r ON r.id = o.restaurant_id
WHERE o.status = 'cancelled'
GROUP BY 1, 2
ORDER BY restoran, soni DESC;

-- 4) Kim bekor qilgan (changed_by) va qaysi holatdan: manba = kitchen | admin | staff | ...
--    kitchen:{id} / admin:{id} / staff:{id} — staff jadvalidagi ism bilan; boshqa qiymatlar o'zi chiqadi.
SELECT split_part(h.changed_by, ':', 1)          AS manba,
       COALESCE(s.name, h.changed_by, '(NULL)')  AS kim,
       COALESCE(prev.status, '?')                AS oldingi_holat,
       COUNT(*)                                  AS soni
FROM order_status_history h
LEFT JOIN staff s
       ON s.id = CASE WHEN h.changed_by ~ '^[a-z]+:[0-9]+$' THEN split_part(h.changed_by, ':', 2)::bigint END
LEFT JOIN LATERAL (
        SELECT p.status
        FROM order_status_history p
        WHERE p.order_id = h.order_id AND p.id < h.id
        ORDER BY p.id DESC
        LIMIT 1
     ) prev ON TRUE
WHERE h.status = 'cancelled'
GROUP BY 1, 2, 3
ORDER BY soni DESC, kim;

-- 5) Faqat manba bo'yicha qisqa jadval
SELECT COALESCE(NULLIF(split_part(changed_by, ':', 1), ''), '(NULL)') AS manba, COUNT(*) AS soni
FROM order_status_history
WHERE status = 'cancelled'
GROUP BY 1
ORDER BY soni DESC;

-- 6) Tarixsiz bekorlar: status = cancelled, lekin tarixda 'cancelled' yozuvi yo'q (kod orqali emas — qo'lda DB/tinker)
SELECT COUNT(*) AS tarixsiz_bekor
FROM orders o
WHERE o.status = 'cancelled'
  AND NOT EXISTS (SELECT 1 FROM order_status_history h WHERE h.order_id = o.id AND h.status = 'cancelled');

ROLLBACK;
```

Izoh: «oldingi_holat» tarixdagi oldingi yozuvdan olinadi; `manba` `kitchen` / `admin` bo'lmasa yoki 6-so'rov noldan katta
bo'lsa — buyurtma kod orqali emas, qo'lda (DB/tinker) bekor qilingan.

## Ma'lum cheklovlar (alohida muammolar)
1. **Qabul qilinmagan (`new`) buyurtma abadiy pending bo'lib qolishi mumkin.** Restoran uni rad eta oladi, admin bekor qila
   oladi, lekin hech kim tegmasa buyurtma o'zi yopilmaydi: faqat push takrori va 7 daqiqada admin ogohlantirishi bor, qoldig'i
   band bo'lib qoladi (faqat restoran qoldiq kiritgan taomlarda). Hal qilish: muddati o'tganda avtomatik `cancelled`
   (alohida vazifa) — `OrderStatusService` orqali o'tsa qoldiq o'zi qaytadi, qulf poygani ham hal qiladi.
2. `on_the_way` dan bekor qilib bo'lmaydi (faqat `delivered`).
3. Mijozning o'zi bekor qilishi **yo'q** (alohida vazifa). Yangi yo'l qo'shilsa u `OrderStatusService::cancel()` orqali
   o'tsin — qoldiq va xabar o'zi ishlaydi.
4. Qisman buyurtma qurilmagan (yuqoriga qarang).
## Deploy (serverda)

`origin/main` da stock bilan birga **street-address** ham bor. Serverda ikkalasi ham hali bo'lmasa, bitta deploy ikkalasini
olib chiqadi: migratsiyalar `2026_11_09_000001` (stock) va `2026_11_10_000001` (`addresses.street`). Ikkalasi ham faqat
**nullable ustun / indeks / CHECK** qo'shadi — mavjud ma'lumot o'zgarmaydi, qayta yozish yo'q. Seeder ishga tushmaydi,
test ma'lumot yo'q (bu commitlar faqat kod va testlar).

**`feature/order-reject-timeout` (new dan rad etish, admin «Bekor qilish», holat qulfi) alohida migratsiya QO'SHMAYDI** —
faqat kod, lang va frontend. Stock bilan birga yoki undan keyin chiqarilsa ham pending migratsiyalar ro'yxati o'zgarmaydi
(faqat stock/street yoki serverda yetishmagan eskilari). Tartib bir xil: backup → pull → build → migrate → up -d →
reload/restart → tekshiruv. Qo'shimcha tekshiruv (deploydan keyin): `/admin → Buyurtmalar` da buyurtma sahifasida
«Bekor qilish» tugmasi (faqat bekor qilinadigan holatda) va `/kitchen` da yangi buyurtmada «❌ Rad etish» chiqishi
(planshetlarda `Ctrl+F5`). Real buyurtmani sinash uchun bekor QILMANG — tugma ko'rinishi yetarli.
```bash
cd /opt/yetkaz
F="-f docker-compose.prod.yml"

# 1) Migratsiyadan OLDIN backup
bash docker/prod/backup.sh
ls -lh backups/ | tail -3                     # bo'sh/juda kichik bo'lmasin (kamida bir necha yuz KB)

# 2) Kod
git pull origin main
git log --oneline -3

# 3) Yangi image (frontend ham shu yerda quriladi)
docker compose $F build app

# 4) Migratsiya — yangi kod ishga tushishidan OLDIN (muvaffaqiyatsiz bo'lsa eski konteynerlar tegilmaydi)
docker compose $F run --rm app php artisan migrate:status --pending      # faqat 2 ta bo'lishi kerak
docker compose $F run --rm app php artisan migrate --force

# 5) Ishga tushirish (image o'zgargan app/horizon/reverb/scheduler qayta yaratiladi)
docker compose $F up -d
docker compose $F ps

# 6) Xotiradagi kodni yangilash (har biri eski kodni xotirada ushlaydi)
docker compose $F exec app php artisan octane:reload
docker compose $F restart horizon
# Mijoz boti production'da webhook bilan `app` ichida ishlaydi (alohida `bot` xizmati YO'Q) —
# octane:reload shuni ham yangilaydi. Lokalda esa: docker compose restart bot

# 7) Tekshirish
docker compose $F exec app php artisan migrate:status | tail -4
docker compose $F exec app php artisan stock:reset-daily                 # xato bermasligi kerak (jim)
docker compose $F exec app php artisan schedule:list | grep stock
docker compose $F exec app php artisan horizon:status
docker compose $F logs --since 5m app horizon | grep -iE "error|exception" | head
```

Qo'lda tekshirish: oshxona planshetlarida `Ctrl+F5` (yangi «Menyu» tabi), keyin `/kitchen → Menyu` ro'yxati ochilsin.
Tugmani sinash — haqiqiy menyuga tegadi: avval `/restaurant` da bitta taomga qoldiq kiriting, «Tugadi/Bor» ni sinab, qiymatni
oldingi holatiga (bo'sh = cheklanmagan) qaytaring. Mini App'da «Faqat N ta qoldi!» / «Tugadi» ni shu taomda ko'ring.

Orqaga qaytarish: `migrate:rollback --step=2` ustunlarni olib tashlaydi (qoldiq va ko'cha ma'lumotlari yo'qoladi) — avval
backup'dan tiklash imkoniyatini ko'ring (`docs/deploy.md` «Backup va tiklash»). Faqat kodni qaytarish uchun oldingi
commitga `git checkout` + `build app` + `up -d` yetadi: yangi ustunlar eski kodga xalaqit bermaydi.
