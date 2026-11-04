# Manzil: ko'cha va mo'ljal

Mijoz manzil yuborganda (Mini App xaritasi yoki bot lokatsiyasi) manzil matnida hech bo'lmasa **ko'cha nomi** yoki
**mo'ljal** bo'lishi kerak. Faqat tuman nomi qolib ketmasligi uchun.

## Nega bu kerak (diagnostika, 2026-11-04)
Qo'rg'ontepa va Xonobod atrofida 37 nuqtalik setka (har tumanda), Nominatim `/reverse`:

| | zoom 16 (bizda) | zoom 17 | zoom 18 |
|---|---|---|---|
| `road` chiqdi | 22% / 24% | 22% / 24% | 22% / 24% |

- **Zoom o'zgartirish yordam bermaydi.** Muammo OSM ma'lumotida: 2 km doirada highway'larning atigi 7% (Qo'rg'ontepa)
  va 3% (Xonobod) i `name` ga ega (uzunlik bo'yicha 11% / 8%). Nominatim ham, Geofabrik extract ham shu ma'lumotdan.
- `road` natijalarining katta qismi aslida **yo'l raqami** (`D137`, `D143`) — ko'cha nomi emas.
- Ko'cha topilmaganda Xonobodda 26/28 nuqtada `village`/`hamlet` (qishloq/mahalla) nomi bor edi, biz uni ishlatmasdik.

## Qanday ishlaydi
`addresses.street` (nullable) — ko'cha nomi. `addresses.note` — **mo'ljal** (UI'da «Mo'ljal»). `address_text` va
`resolved_address` serverda **ko'cha + qishloq/mahalla + tuman** dan yig'iladi (`AddressText::compose`, takrorsiz):
`Bobur ko'chasi 12, Yangi hayot, Qo'rg'ontepa tumani`.

**Geokoder** (`AddressGeocoder::describe`): `street` (yo'l raqami — `D137`, `M-39`, `A373` — ko'cha hisoblanmaydi),
`locality` (neighbourhood → quarter → suburb → hamlet → village; tuman nomiga teng bo'lsa olinmaydi), `district`.
Kesh: muvaffaqiyatli javob 1 kun, **xato** (tarmoq, 4xx/5xx) `geo.nominatim.error_cache_seconds` (standart 60 s).

**Majburiylik** (`AddressService::resolvePlace`, API darajasida ham): geokoder ko'cha topmasa va mijoz ko'cha ham, mo'ljal ham
kiritmasa — `422`, `errors.street` (uz/ru). Ko'cha topilsa mo'ljal ixtiyoriy. Mijoz kiritgan ko'cha geokoder topganidan ustun;
mijozdan kelgan `address_text` e'tiborga olinmaydi.

- **API:** `POST /api/addresses` (`street`, `note`), `PATCH /api/addresses/{id}` (ko'cha yoki nuqta o'zgarsa matn qayta
  yig'iladi; boshqa maydonlar — yorliq, kirish, qavat — eski ko'chasiz manzilga tegmaydi), `GET /api/geo/reverse`
  (`street`, `locality`, `district_name`, `address_text`).
- **Mini App «Yangi manzil»:** «Ko'cha» maydoni geokoderdan to'ldiriladi va tahrirlanadi, «Mo'ljal» ixtiyoriy; geokoder
  ko'cha topmasa kamida bittasi majburiy («Saqlash» o'chiq, tushuntirish chiqadi). Tuman/qishloq avtomatik qo'shiladi.
- **Bot:** geokoder ko'cha topsa manzil savolsiz saqlanadi. Topmasa bir marta: «Ko'cha nomini yozing» (yoki «Ko'cha nomini
  bilmayman» → «Mo'ljalni yozing»). **O'tkazib yuborish yo'q.** Mavjud manzilga qo'shishda «❌ Bekor qilish» bor;
  ro'yxatdan o'tishda lokatsiya majburiy — bekor qilish yo'q. Suhbat paytida lokatsiya qayta yuborilsa shu nuqta olinadi.

## Buyurtmada
Snapshot'ga `street` va `landmark` (= `addresses.note`) tushadi (buyurtma paytidagi qiymat, keyin manzil o'zgarsa ham eskisi
o'zgarmaydi). Mo'ljal ko'rinadi: oshxona kartasi (🧭), chek (ESC/POS `MOLJAL:` va brauzer cheki, uz/ru), Telegram xabarlari
(oshxona boti, restoran egasi DM, mijoz tasdig'i — mijoz tilida), admin va restoran buyurtma tafsiloti. Eski buyurtmalar
o'zgarmaydi (snapshot'da kalit yo'q).

## Mavjud manzillar
`street` ustuni NULL, backfill yo'q, matn o'zgarmaydi. Eski manzilni tahrirlab ko'cha kiritilsa matn qayta yig'iladi.

## Deploy
Migratsiya `2026_11_10_000001` faqat nullable `addresses.street` qo'shadi (tez, qayta yozish yo'q). Mini App va `/kitchen`
frontend'i qayta quriladi (`build app`), planshetda `Ctrl+F5`. Ixtiyoriy: `NOMINATIM_ERROR_CACHE_SECONDS` (standart 60).

## Keyingi vazifalar
- «Eng yaqin nomli ko'cha» fallback'i (Overpass/PostGIS) — diagnostika ko'rsatdiki, OSM'dagi nomlar kamligi sababli
  foydasi kichik (150 m radiusda 27% / 8%, Nominatim allaqachon shuncha topadi); alohida vazifa.
- Mijozlar kiritgan ko'cha nomlari lug'ati (koordinata + nom) — vaqt o'tgach OSM'ga bog'liq bo'lmagan fallback.
