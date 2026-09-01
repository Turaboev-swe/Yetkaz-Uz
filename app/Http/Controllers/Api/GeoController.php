<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DistrictResource;
use App\Http\Resources\RegionResource;
use App\Models\District;
use App\Models\Region;
use App\Services\Geo\AddressGeocoder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Ma'muriy hududlar — Mini App'da tuman bo'yicha filtrlash uchun.
 */
class GeoController extends Controller
{
    /** GET /api/regions — faol viloyatlar (tumanlari bilan). */
    public function regions(): AnonymousResourceCollection
    {
        return RegionResource::collection(
            Region::query()->active()
                ->with(['districts' => fn ($q) => $q->active()->orderBy('name')])
                ->orderBy('name')
                ->get(),
        );
    }

    /** GET /api/districts?region_id= — faol tumanlar. */
    public function districts(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'region_id' => ['nullable', 'integer', 'exists:regions,id'],
        ]);

        return DistrictResource::collection(
            District::query()->active()
                ->when($validated['region_id'] ?? null, fn ($q, $id) => $q->where('region_id', $id))
                ->orderBy('name')
                ->get(),
        );
    }

    /**
     * GET /api/geo/reverse?lat=&lng= — nuqta uchun tuman + manzil matni (o'zbekcha).
     * "Yangi manzil" xaritasida nuqta tanlanganda ishlatiladi.
     */
    public function reverse(Request $request, AddressGeocoder $geocoder): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return response()->json([
            'data' => $geocoder->describe((float) $validated['lat'], (float) $validated['lng']),
        ]);
    }
}
