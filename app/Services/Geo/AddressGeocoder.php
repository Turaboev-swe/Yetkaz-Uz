<?php

namespace App\Services\Geo;

use App\Models\District;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Nuqta (lat/lng) -> tuman + o'qiladigan manzil matni.
 *
 * Tuman DOIM `districts` jadvalidagi o'zbekcha nom bilan qaytadi
 * (eng yaqin markaz bo'yicha; Nominatim county mos kelsa — o'sha).
 * Ko'cha/uy Nominatim reverse'dan (accept-language=uz), xatoda —
 * faqat tuman nomi.
 */
class AddressGeocoder
{
    /** @return array{district_id: int|null, district_name: string|null, address_text: string} */
    public function describe(float $lat, float $lng): array
    {
        $nearest = $this->nearestDistrict($lat, $lng);
        $osm = $this->reverse($lat, $lng);

        $district = $this->matchDistrict($osm['county'] ?? $osm['city'] ?? null) ?? $nearest;

        $street = trim(implode(' ', array_filter([
            $osm['road'] ?? null,
            $osm['house_number'] ?? null,
        ])));

        $parts = array_filter([
            $street !== '' ? $street : ($osm['neighbourhood'] ?? $osm['suburb'] ?? null),
            $district?->name,
        ]);

        $text = $parts !== []
            ? implode(', ', $parts)
            : sprintf('%.6f, %.6f', $lat, $lng);

        return [
            'district_id' => $district?->id,
            'district_name' => $district?->name,
            'address_text' => mb_substr($text, 0, 250),
        ];
    }

    public function nearestDistrict(float $lat, float $lng): ?District
    {
        return District::query()->active()->get()
            ->sortBy(fn (District $d) => $this->haversine($lat, $lng, (float) $d->center_lat, (float) $d->center_lng))
            ->first();
    }

    /**
     * Nominatim reverse -> address bo'laklari (uz tilida). Xatoda [].
     *
     * @return array<string, string>
     */
    private function reverse(float $lat, float $lng): array
    {
        $key = sprintf('geo:rev:%.4f:%.4f', $lat, $lng);

        return Cache::remember($key, now()->addDay(), function () use ($lat, $lng) {
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

                return [];
            }

            if (! $response->successful()) {
                return [];
            }

            return array_map('strval', $response->json('address') ?? []);
        });
    }

    /** Nominatim county/city nomini `districts` jadvaliga solishtiradi. */
    private function matchDistrict(?string $name): ?District
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $target = $this->normalize($name);

        return District::query()->active()->get()
            ->first(fn (District $d) => $this->normalize($d->name) === $target);
    }

    /** Kichik harf, o'zbek apostroflari birlashtirilgan, " tumani"/" shahri" olib tashlangan. */
    private function normalize(string $s): string
    {
        $s = str_replace(['ʻ', 'ʼ', 'ʹ', '‘', '’', '`', '´'], "'", mb_strtolower(trim($s)));
        $s = preg_replace('/\s+(tumani|shahri|tuman|district|rayoni|район[а]?)$/u', '', $s) ?? $s;

        return trim($s);
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
