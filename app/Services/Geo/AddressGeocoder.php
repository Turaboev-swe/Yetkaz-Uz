<?php

namespace App\Services\Geo;

use App\Models\District;
use App\Support\AddressText;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Nuqta (lat/lng) -> tuman + ko'cha + qishloq/mahalla + o'qiladigan manzil matni.
 *
 * Tuman DOIM `districts` jadvalidagi o'zbekcha nom bilan qaytadi
 * (eng yaqin markaz bo'yicha; Nominatim county mos kelsa — o'sha).
 * Ko'cha/uy Nominatim reverse'dan (accept-language=uz). Yo'l RAQAMI (D137, M-39) ko'cha emas:
 * OSM'da ko'chalarning aksariyati nomsiz, Nominatim bunda `road` o'rniga `ref` qaytaradi.
 * Ko'cha topilmasa qishloq/mahalla (hamlet/village/...) nomi, u ham bo'lmasa — faqat tuman.
 */
class AddressGeocoder
{
    /**
     * @return array{district_id: int|null, district_name: string|null, street: string|null, locality: string|null, address_text: string}
     */
    public function describe(float $lat, float $lng): array
    {
        $nearest = $this->nearestDistrict($lat, $lng);
        $osm = $this->reverse($lat, $lng);

        $district = $this->matchDistrict($osm['county'] ?? $osm['city'] ?? null) ?? $nearest;
        $street = $this->streetFrom($osm);
        $locality = $this->localityFrom($osm, $district?->name);

        // Xom koordinata HECH QACHON matn sifatida qaytmaydi — bo'lmasa tuman nomi.
        $text = AddressText::compose($street, $locality, $district?->name);

        return [
            'district_id' => $district?->id,
            'district_name' => $district?->name,
            'street' => $street,
            'locality' => $locality,
            'address_text' => mb_substr($text, 0, 250),
        ];
    }

    /**
     * Nuqta -> qisqa, o'qiladigan manzil ("Bobur ko'chasi 12, Yangi qishloq, Qo'rg'ontepa tumani").
     * Manzil yaratilganda `resolved_address` uchun keshlanadi.
     *
     * Nominatim ishlamasa NULL qaytadi — chaqiruvchi o'z zaxirasini ishlatadi
     * (tuman + address_text yoki label). `describe()` dan farqi: xom koordinata
     * qaytarmaydi.
     */
    public function resolve(float $lat, float $lng): ?string
    {
        $osm = $this->reverse($lat, $lng);

        if ($osm === []) {
            return null;
        }

        $district = $this->matchDistrict($osm['county'] ?? $osm['city'] ?? null)
            ?? $this->nearestDistrict($lat, $lng);

        $text = AddressText::compose($this->streetFrom($osm), $this->localityFrom($osm, $district?->name), $district?->name);

        return $text !== '' ? mb_substr($text, 0, 255) : null;
    }

    public function nearestDistrict(float $lat, float $lng): ?District
    {
        return District::query()->active()->get()
            ->sortBy(fn (District $d) => $this->haversine($lat, $lng, (float) $d->center_lat, (float) $d->center_lng))
            ->first();
    }

    /**
     * Ko'cha nomi (+ uy raqami). Yo'l raqami (`ref`) ko'cha emas; uy raqami ko'chasiz ma'noga ega emas.
     *
     * @param  array<string, string>  $osm
     */
    private function streetFrom(array $osm): ?string
    {
        $road = trim((string) ($osm['road'] ?? ''));

        if ($road === '' || AddressText::isRouteRef($road)) {
            return null;
        }

        return trim($road.' '.trim((string) ($osm['house_number'] ?? '')));
    }

    /**
     * Qishloq/mahalla nomi: kichigidan kattasiga (neighbourhood -> quarter -> suburb -> hamlet -> village).
     * Tuman nomi bilan bir xil bo'lsa (masalan town = tuman markazi) — olinmaydi.
     *
     * @param  array<string, string>  $osm
     */
    private function localityFrom(array $osm, ?string $districtName): ?string
    {
        foreach (['neighbourhood', 'quarter', 'suburb', 'hamlet', 'village'] as $key) {
            $name = trim((string) ($osm[$key] ?? ''));

            if ($name === '') {
                continue;
            }

            if ($districtName !== null && AddressText::normalize($name) === AddressText::normalize($districtName)) {
                continue;
            }

            return $name;
        }

        return null;
    }

    /**
     * Nominatim reverse -> address bo'laklari (uz tilida). Xatoda [].
     *
     * Kesh: muvaffaqiyatli javob 1 kun; XATO (tarmoq, 4xx/5xx) atigi `geo.nominatim.error_cache_seconds` (standart 60 s) —
     * Nominatim bir marta yiqilsa shu nuqta kun bo'yi bo'sh qolmasin, lekin har so'rov unga urilib ham ketmasin.
     *
     * @return array<string, string>
     */
    private function reverse(float $lat, float $lng): array
    {
        $key = sprintf('geo:rev:%.4f:%.4f', $lat, $lng);

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        [$address, $ok] = $this->fetchReverse($lat, $lng);

        Cache::put($key, $address, $ok
            ? now()->addDay()
            : now()->addSeconds((int) config('geo.nominatim.error_cache_seconds', 60)));

        return $address;
    }

    /** @return array{0: array<string, string>, 1: bool} [address bo'laklari, so'rov muvaffaqiyatlimi] */
    private function fetchReverse(float $lat, float $lng): array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => config('geo.nominatim.user_agent')])
                ->timeout(8)
                ->get(rtrim(config('geo.nominatim.base_url'), '/').'/reverse', [
                    'lat' => $lat,
                    'lon' => $lng,
                    'format' => 'jsonv2',
                    'accept-language' => 'uz',
                    'zoom' => 16,
                    'addressdetails' => 1,
                ]);
        } catch (\Throwable $e) {
            Log::warning('[geo] reverse xato', ['msg' => $e->getMessage()]);

            return [[], false];
        }

        if (! $response->successful()) {
            return [[], false];
        }

        return [array_map('strval', $response->json('address') ?? []), true];
    }

    /** Nominatim county/city nomini `districts` jadvaliga solishtiradi. */
    private function matchDistrict(?string $name): ?District
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $target = AddressText::normalize($name);

        return District::query()->active()->get()
            ->first(fn (District $d) => AddressText::normalize($d->name) === $target);
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
