# Yetkaz — loyihaning hozirgi holati (2026-09-08)

> Bu hujjat suhbat davomidagi tekshiruvning natijasi. Ikki qismdan iborat:
> **A.** lokal test muhitining production'dan izolyatsiyasi,
> **B.** har bir funksiyaning "qanday ishlashi kerak" / "hozir qanday" holati.
>
> Kodga hech qanday o'zgartirish kiritilmadi — faqat tekshiruv va hujjatlash.
>
> - HEAD: `99bc7da` (2026-09-07)
> - Laravel 12.68.0
> - Testlar: **257 passed** (1374 assertion), `docker compose exec app php artisan test`
> - Loyihada 65 ta commit
>
> Bog'liq hujjatlar: [Claude.md](../Claude.md) (spetsifikatsiya), [audit-2026-09.md](audit-2026-09.md)
> (PROD-1…8, REPORT-1…6 — hammasi yopilgan), [deploy.md](deploy.md).

---

## A. Lokal muhit — production'dan izolyatsiya

### A1. Test bot tokeni

- `.env` da `TELEGRAM_BOT_TOKEN` **qo'yilgan va yaroqli** (qiymat bu yerga yozilmaydi).
- `getMe` → bot `id=8501544851`, username **`@RasmUstasiBot`** ("VisionCraft").
  Nomi loyihaga mos emas, lekin foydalanuvchi shu botда test qilishni tasdiqladi.
- Token production bot tokenidан **boshqa** — ikkalasi bir vaqtда ishlasa ham
  `getUpdates` 409 Conflict bo'lmaydi (409 token bo'yicha, tokenlar har xil).

### A2. Docker Compose stack (dev)

`docker compose up -d` — 7 konteyner, hammasi ishlayapti:

| Konteyner | Holat | Izoh |
|---|---|---|
| `yetkaz-app-1` | Up | Octane (RoadRunner), `localhost:8010`, `/up` va `/app` → 200 |
| `yetkaz-bot-1` | Up | `nutgram:run` long polling, RestartCount=0, xatosiz |
| `yetkaz-horizon-1` | Up (healthy) | navbat ishchilari |
| `yetkaz-postgres-1` | Up (healthy) | PostGIS 16-3.4 |
| `yetkaz-redis-1` | Up (healthy) | — |
| `yetkaz-reverb-1` | Up | WebSocket, `localhost:8080` |
| `yetkaz-scheduler-1` | Up | `schedule:work` |

Migratsiyalar: 21 tasi ham `Ran`. Seed: 5 restoran, 11 xodim, 0 foydalanuvchi, 0 buyurtma.

**Yo'lда tuzatilgan ikki muammo** (kompyuter to'satdan o'chgani sabab):
1. Redis AOF fayli buzilgan edi → `redis-check-aof --fix` (oxiridan 1245 bayt kesildi, ma'lumot yo'qolmadi).
2. Konteyner ичida DNS vaqtincha ishlamay qoldi → `docker compose restart bot` bilan tiklandi.

### A3. Production bilan umumiy resurs — YO'Q ✅

| Resurs | Lokal (dev) | Production | Izolyatsiya |
|---|---|---|---|
| Compose loyiha nomi | `yetkaz` | `yetkaz-prod` | volume nomlari har xil (`yetkaz_pgdata` ≠ `yetkaz-prod_pgdata`) |
| Postgres | lokal konteyner, `DB_HOST=postgres` (ichki tarmoq) | server `.env`, alohida konteyner | ulanmaydi |
| Redis | lokal konteyner, `REDIS_HOST=redis` (ichki tarmoq) | alohida | ulanmaydi |
| Bot tokeni | test bot (`@RasmUstasiBot`) | prod bot (server `.env` da) | har xil |
| `APP_ENV` | `local` | `production` | — |
| Kod | bind-mount (`./:/app`) | image ичiga qurilgan | — |

### A4. Production qoldig'i tekshiruvi — TOZA ✅

- `.env` да **`.env.prod` / `.env.production` fayli yo'q** — faqat `.env` (lokal) va `.env.example`.
- Test botда **webhook o'rnatilmagan** (`getWebhookInfo` → `url: ""`), `pending_update_count: 0`.
- `telegram:webhook:set` buyrug'i `APP_ENV=production` + https ga qattiq bog'langan —
  lokalда tasodifan webhook o'rnatib bo'lmaydi.
- `TELEGRAM_MINI_APP_URL` — `trycloudflare.com` tunnel manzili (lokal), production
  domeniga ishora qilmaydi.
- `DB_PASSWORD=secret` — hujjatlashtirilgan lokal-only default; prod server o'z
  `.env` да kuchli parol ishlatadi (`docker-compose.prod.yml` da `DB_PASSWORD` majburiy).

**Xavfli qoldiq topilmadi.**

### A5. Ro'yxatdan o'tish oqimi — live test kutilmoqda

Mashina tomoni tekshirildi: bot polling'да, webhook yo'q, lokal baza bo'sh
(`users=0`), `RegistrationFlowTest` (6 test) yashil. **Telegram akkountдан
haqiqiy `/start` yuborish — foydalanuvchi tomonidan bajariladi** (Claude Telegram
foydalanuvchisi emas). Yangi bo'sh lokal bazada bo'ladi, production
foydalanuvchilariga aloqasi yo'q.

---

## B. Funksiyalar holati

Ustunlar: **Kutilgan** — spetsifikatsiya bo'yicha bo'lishi kerak; **Hozir** —
kodда qanday; **Sinov** — 🟢 avtotest bor / 🟡 kod bor, e2e sinalmagan / ⚪ sinalmagan.

### B1. Mijoz oqimi (Telegram bot + Mini App)

| Funksiya | Kutilgan | Hozir | Sinov |
|---|---|---|---|
| Ro'yxatdan o'tish | telefon (`request_contact`) → ism → lokatsiya, bir marta; `profile_completed=true` bo'lgach qayta so'ralmaydi | `RegistrationConversation`: `askPhone → handlePhone → handleName → handleLocation`. Telefon faqat tugmadan; boshqaning kontakti rad etiladi; ism <2 belgi yoki `/` bilan boshlansa rad; lokatsiya "Uy" yorlig'i + `is_default=true` bilan saqlanadi | 🟢 `RegistrationFlowTest` |
| Bir nechta manzil | "Uy"/"Ish", biri `is_default` | `AddressService` + `/api/addresses` CRUD (throttle 20/daq); Mini App'да `AddressPickerSheet`, `AddressConfirmSheet` | 🟢 `AddressApiTest`, `AddressGeocodingTest` |
| Restoran ro'yxati | `is_open=true` + ish vaqti ичida + radius (`ST_DWithin`); radiusdan tashqari ko'rinmaydi; tuman — ko'rsatish filtri | `RestaurantFinder::deliveringTo()`. **Farq:** `include_closed=1` bilan yopiqlar ham qaytadi (`is_open_now` bayrog'i, ochiqlar yuqorida) — Mini App ro'yxati uchun | 🟢 `RestaurantApiTest` |
| Umumiy taom qidiruv | radiusдagi barcha restoranlardan taom, narx+ETA bilan; `pg_trgm` | `ProductSearchService` + `/api/search` (`SearchController`) | 🟢 `SearchApiTest` |
| Menyu | faol kategoriyalar + mavjud taomlar, N+1 yo'q | `MenuService::forRestaurant()`, `/api/restaurants/{id}/menu`; Mini App `Menu.jsx` + `CategoryTabs` | 🟢 `RestaurantApiTest` |
| Savat | restoran bo'yicha alohida; restoran almashsa saqlanadi; aralash savat yo'q | Mini App front-end store (`resources/js/miniapp/store`), `CartBar`, `Cart.jsx`. Buyurtma bitta restorandan (`OrderService::place`) | 🟡 front-end store avtotestsiz |
| Checkout | ETA oraliq ko'rsatiladi; narx bazadан; min. summa; radius tekshiruvi | `Checkout.jsx` → `/api/orders/estimate` (ETA oraliq) → `/api/orders` (throttle 5/daq). `OrderService`: narx **bazadан** (`resolveLines`), `min_order_amount`, `canDeliver`, yopiq restoran rad | 🟢 `OrderApiTest` |
| Buyurtma tasdig'i (chek ko'rinishi) | mijozga chek uslubidagi xabar | `SendOrderConfirmationToCustomer` job → `ReceiptFormatter` | 🟢 `OrderConfirmationTest` |
| Status xabarlari | har status o'zgarishi mijozga bot orqali; til: uz/ru | `NotifyCustomerOfStatusChange` (3 urinish): accepted / preparing / on_the_way / delivered (pickup: "picked_up") / cancelled. Bloklagan mijoz — jimgina o'tadi | 🟢 `OrderNotificationTest`, `CourierInfoTest` |
| "Yo'lга chiqdi" + kuryer | kuryer ismi/telefoni ko'rsatiladi | `onTheWayText()`: `courier_name`/`courier_phone` bo'lsa ular, aks holда restoran telefoni | 🟢 `CourierInfoTest` |
| ETA oraliq | aniq raqam emas, oraliq (42 → 35–50) | `EtaEstimate::fromMinutes`: `low = max(5, round5(m−5))`, `high = round5(m+10)` | 🟢 `EtaEstimatorTest` |

### B2. Oshxona oqimi

| Funksiya | Kutilgan | Hozir | Sinov |
|---|---|---|---|
| `/kitchen` paneli | planshetда ochiq React sahifa (Filament emas), real-time, faqat o'z restorani | `resources/js/kitchen/` (alohida React ilova), `KitchenController`. `panel.session:yetkaz_staff_session` + `web` middleware. `Staff::canManageKitchen()` (owner yoki kitchen_staff) | 🟢 `KitchenPanelTest`, `KitchenAuthTest` |
| `/kitchen/login` | `kitchen_staff` faqat shu yerdан (Filament panellariga kira olmaydi); `platform_admin` kira olmaydi; nofaol xodim rad | `KitchenAuthController` | 🟢 `KitchenAuthTest` (8 test) |
| Yangi buyurtma real-time | Reverb orqali darhol + ovozli signal | `OrderPlaced` (`ShouldBroadcast`) → `private-restaurant.{id}` kanali; front-end signal | 🟡 broadcast kodда bor, brauzerда e2e sinalmagan |
| Status tugmalari | qabul → tayyorlanmoqda → yo'lга chiqdi → yetkazildi (pickup: `on_the_way` tashlanadi) | `PATCH /kitchen/orders/{order}/advance` → `OrderStatusService::advance()`; tranzaksiya + tarix + `OrderStatusChanged` + mijoz xabari | 🟢 `KitchenPanelTest`, `OrderStatusService` testlari |
| Kuryer tanlash (dropdown) | matn yozish emas, restoran xodimlaridан tanlash | `GET /kitchen/couriers` (o'z restoranining faol xodimlari) → `advance` da `courier_staff_id`; tanlanган paytдagi `name`/`phone` snapshot buyurtmaга yoziladi | 🟢 `KitchenCourierTest` |
| Telegram callback tugmalari | oshxona xodimi bot xabaridан statusni oldinга suradi | `NotifyKitchenStaffOfNewOrder` (kitchen_staff + owner, `telegram_chat_id` to'ldirilган) → "keyingi bosqich" tugmasi; `kadv:{orderId}:{expected}` → `KitchenCallbackHandler`. Birinchi bosган g'olib | 🟢 `KitchenBotCallbackTest`, `KitchenCourierTest` |
| Dispatch muvaffaqiyatsizligi | buyurtma yo'qolmaydi; 3 urinish backoff; oshxona + admin ogohlantirish | `DispatchOrderJob` (3 urinish, backoff 10/30/60s); `failed()` → `orders.dispatch_failed_at` + `OrderDispatchFailed` broadcast | 🟢 `OrderDispatchTest` |

### B3. Admin / restoran panellari (Filament 3)

| Funksiya | Kutilgan | Hozir | Sinov |
|---|---|---|---|
| Guard ajratilishi | ikki panel `staff` guardда | **Yangilangan:** `/admin` → `admin` guard (cookie `yetkaz_admin_session`), `/restaurant` + `/kitchen` → `staff` guard (cookie `yetkaz_staff_session`). Ikkalasi ham `staff` jadvalidан; bitta brauzerда admin + owner bir vaqtда | 🟢 `PanelAccessTest`, `KitchenAuthTest` |
| `/admin` (platform_admin) | restoranlar CRUD, xodimlar, barcha buyurtmalar, hisobotlar | `app/Filament/Admin/` — Resource'lar (Restaurant, Staff, …), hisobot dashboard'lari | 🟢 `PanelPagesRenderTest`, `PanelAccessTest` |
| `/restaurant` (restaurant_owner) | faqat o'z restorani — kategoriya, taom, ish vaqti, `is_open`, o'z buyurtmalari | `app/Filament/Restaurant/`. Izolyatsiya: `RestaurantScope` global scope (`Category`, `Product`, `Order`) + faqat `restaurant_owner` auth bo'lganда faol (bot/API/CLI/admin — no-op) | 🟢 `RestaurantIsolationTest`, `RestaurantReportIsolationTest` |
| Narx so'mда → tiyinда | Filament mutator; har o'zgarish `product_price_history` ga (kim/qachon/eski/yangi); narx tarixi taom sahifasida | Product observer + `ProductManagementTest` | 🟢 `ProductManagementTest` |
| `is_available` toggle | bir bosishli | taomlar ro'yxatida toggle | 🟢 `MenuActionsTest` |
| Ish vaqti "Har kuni" | 7 kunга bir xil oraliq tez to'ldirish | `WorkHoursForm`: `{day: "everyday"}` — faqat o'z qatori bo'lmagan kunlarni to'ldiradi (alohida kun doim ustun). 7 kun bir xil bo'lsa `toRows()` bitta "Har kuni" qatoriga jamlaydi | 🟢 `WorkHoursEverydayTest`, `WorkHoursFormTest`, `WorkHoursTest` |
| Restoran joylashuvi | viloyat → tuman → xaritada nuqta (lat/lng qo'lда emas) | `RestaurantResource` / `RestaurantLocationTest`; xarita picker | 🟢 `RestaurantLocationTest` |
| Xodimга `telegram_chat_id` | admin panelдан kiritiladi (faqat saqlash) | `StaffResource` maydoni; `StaffSeeder` `TELEGRAM_DEV_NOTIFY_CHAT_ID` ni admin/owner/kitchen ga yozadi | 🟢 `StaffTelegramChatIdTest` |

### B4. ETA hisoblash

| Element | Kutilgan (Claude.md) | Hozir (`EtaEstimator`) |
|---|---|---|
| Formula | `pishirish + navbat_jarimasi + kuryer_kutish + yo'l + bufer` | bir xil |
| pishirish | `max(prep_time_min)` (parallel) | `max(1, maxPrepMin)` |
| navbat jarimasi | `min(faol_buyurtma * 2, 20)` | bir xil (`activeOrders`, global scope'siz) |
| kuryer kutish | 5 | 5 (pickup: 0) |
| yo'l vaqti | `(masofa/tezlik)*60`; tezlik 22 (07:30–10:00, 17:00–20:00) / 28 | bir xil; `isPeak` `Asia/Tashkent`; **masofa = to'g'ri chiziq × 1.35** (OSRM lokalда yo'q — faqat Haversine fallback) |
| bufer | 5 | 5 |
| kesh | restoran+manzil juftligi 1 soat Redis | `ETA_CACHE_TTL=3600` |
| ko'rsatish | oraliq, 5 ga yaxlit | `low=max(5,round5(m−5))`, `high=round5(m+10)`, `minutes=max(10,…)` |

**Eslatma:** lokal compose'да OSRM konteyner yo'q → ETA doim Haversine × 1.35.
Production'да OSRM (`OSRM_BASE_URL`) qo'shilса aniqroq bo'ladi.

### B5. Boshqa

| Funksiya | Kutilgan | Hozir | Sinov |
|---|---|---|---|
| Buyurtma raqami | `YT-` + 6 raqam (`YT-483920`), takrorlanmas | `OrderNumberGenerator`: `YT-` + `str_pad(random_int(0,999999),6)`, bazaда 10 urinishгacha tekshiradi. Eski `YK-*` o'zgarmaydi | 🟢 `OrderNumberGeneratorTest` |
| Rate limiting | — | `api-read` 60/daq, `addresses` 20/daq, `orders` 5/daq, webhook `120,1`; foydalanuvchi bo'yicha, Redis (`AppServiceProvider::configureRateLimiting`) | 🟢 `RateLimitTest` (PROD-1) |
| Webhook secret tekshiruvi | — | `POST /api/telegram/webhook/{token}` — ikki qatlamli: URL path + `X-Telegram-Bot-Api-Secret-Token`, ikkalasi `TELEGRAM_WEBHOOK_SECRET`. Mos kelmasa 404. Bo'sh secret → doim 404 (hozirgi lokal holat). Handler xatosi hech qachon 5xx qaytarmaydi | 🟢 `WebhookEndpointTest` (PROD-2/6) |
| Token redaction (loglar) | — | `RedactingBotClientHandler` + `RedactSecretsProcessor` + log kanali; bot tokeni `[REDACTED]` | 🟢 `SecretRedactor*`, `RedactingBotClientHandlerTest` (PROD-4) |
| Backup | — | `docker/prod/backup.sh` — kunlik `pg_dump | gzip -9`, 14 kun retention, kichik dump = xato, disk ogohlantirish; cron 03:00 Asia/Tashkent (`yetkaz-backup.cron`). **Faqat prod** | ⚪ prod serverда |
| Chek printeri (print-agent) | oshxona kompyuterида Python, Reverb bilan, lokal SQLite navbat, `printed_at` tasdiq | `print-agent/` (Python 3.9+): `private-restaurant.{id}.print` kanali, `print.requested` → ESC/POS, SQLite `queue.db` offline navbat, har 15s retry, backend tasdiq. `PrintJobRequested` (`ShouldBroadcastNow`). Provisioning: `print-agent:provision`. `PosType::EscPos` → `EscPosDriver`, aks holда `ManualDriver` | 🟡 **faqat `simulate` rejim** — haqiqiy printerда sinalmagan. `OrderDispatchTest` bor |
| POS drayverlari | `DispatchDriver` interfeysi: jowi/poster/iiko/escpos/manual | interfeys bor; `OrderDispatcher` faqat `EscPos` va `Manual` ni ulaydi. jowi/poster/iiko — **kod yo'q**, `default => manual` | — |

### B6. Reyting tizimi — ORQAGA QAYTARILGAN ⚠️

**Kodда reyting/sharh (rating/review) tizimi YO'Q.** Muhokama qilingan, lekin
qo'shilmagan yoki olib tashlangan. Kelajakда chalkashmaslik uchun:

- `orders` да reyting ustuni yo'q, `ratings`/`reviews` jadvali yo'q.
- `OrderStatsService` да "restoranlar reytingi" bor — bu **buyurtma soni bo'yicha
  reyting** (hisobot), mijoz bahosi emas.
- Mijoz bosh menyusида "Fikr bildirish" (`FeedbackHandler`) bor — bu umumiy
  murojaat/shikoyat, taom yoki restoran bahosi emas.

---

## C. Ochiq masalalar (hal qilinmagan)

| Masala | Holat |
|---|---|
| **Higgsfield API** integratsiyasi | Bu repoда umuman kod yo'q. Agar rejaда bo'lsa — alohida ish. To'liq ishlagan holat tasdiqlanmagan |
| **To'lov (Payme / Click)** | Boshlanmagan. `payment_method` (`cash` default), `payment_status` (`Pending`) ustunlari bor, integratsiya kodи yo'q. Claude.md 10-bosqich |
| **Restoran obuna / komissiya tizimi** | Faqat muhokama qilingan, kod yo'q. Obuna/komissiya/tarif jadvali yoki modeли yo'q. (`commit c9b4365 "MINOR:subscription"` — nomи chalg'ituvchi, aslида u Reverb kanal *subscription* i haqида, biznes obuna emas) |
| **print-agent haqiqiy printerда** | Faqat `simulate` rejimда sinalgan. `network` rejim (`PRINTER_HOST:9100`, termoprinter) real qurilmада sinalmagan |
| **jowi / poster / iiko POS** | Interfeys bor, drayver kodи yo'q. Jowi — hamkorlik shartnomasi kerak (Claude.md "Ochiq savollar"). Poster'dан boshlash rejalashtirilган (9-bosqich) |
| **OSRM (lokal)** | Lokal compose'да OSRM konteyner yo'q → ETA doim Haversine × 1.35. Production wiring tekshirilishi kerak |
| **Reverb e2e** | `/kitchen` real-time yangi buyurtma + ovozli signal brauzerда uchidан-uchга sinalmagan (kod va broadcast bor) |
| **Mini App savat store** | Front-end store (restoran bo'yicha alohida savat) avtotest bilan qoplanmagan |
| **Kuryer boshqaruvi** | Loyihада yo'q. Keyingi bosqichда alohida kuryer boti (Claude.md "Ochiq savollar") |
| **trycloudflare tunnel beqarorligi** | Bepul quick tunnel manzili har restartда o'zgaradi va uzilib qoladi. Barqaror lokal test uchun `ngrok` (hisob bilan doimiy subdomain) yaxshiroq |

---

## D. Claude.md dan asosiy farqlar (drift)

Spetsifikatsiya ([Claude.md](../Claude.md)) orqada qolган joylar:

1. **Auth guard.** Claude.md: "ikki panel `staff` guard bilan". Aslида `/admin`
   alohida `admin` guard + alohida sessiya cookie (commit `94e7ed6`).
2. **Baza sxemasi.** Claude.md sxemасida yo'q, aslида bor:
   - `orders`: `delivery_type`, `note`, `address_snapshot`, `cancelled_at`,
     `dispatch_failed_at`, `courier_name`, `courier_phone`, `courier_staff_id`
   - `restaurants`: `notify_chat_id`, `print_agent_token`, `printer_host`,
     `printer_port`, `location` (PostGIS geography), `old_price` (products)
   - `staff`: `telegram_chat_id`, `phone`
   - `regions` / `districts` (Claude.md да bor, README'да eski "cities" nomi)
3. **Ro'yxatdан o'tish tartibi.** Claude.md matnида "ism, telefon, lokatsiya";
   kodда **telefon → ism → lokatsiya**.
4. **Yopiq restoranlar.** Claude.md: ro'yxatда umuman ko'rinmaydi. Aslида
   `include_closed=1` bilan `is_open_now` bayrog'i bilan qaytadi (ochiqlar tepада).
5. **`OrderDispatcher`.** Claude.md `Drivers/` да `JowiDriver, PosterDriver,
   IikoDriver, EscPosDriver` sanaган. Aslида faqat `EscPosDriver`, `ManualDriver`
   mavjud; POS drayverlari yo'q.
6. **`dispatched_at` vs `dispatch_failed_at`.** Dispatch muvaffaqiyatsizligи
   alohida `dispatch_failed_at` ustunига yoziladi (Claude.md да aytilmagan).

> Tavsiya: `Claude.md` ni shu ro'yxat asosида yangilash (alohida ish, bu bosqichда emas).
