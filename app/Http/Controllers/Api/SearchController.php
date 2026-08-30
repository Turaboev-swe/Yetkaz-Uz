<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesUserAddress;
use App\Http\Controllers\Controller;
use App\Http\Resources\SearchResultResource;
use App\Services\Search\ProductSearchService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SearchController extends Controller
{
    use ResolvesUserAddress;

    public function __construct(private readonly ProductSearchService $search) {}

    /** GET /api/search?q=&address_id= — yetkazish radiusidagi barcha restoranlardan taom qidiruvi. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:60'],
        ]);

        $address = $this->resolveUserAddress($request);

        return SearchResultResource::collection(
            $this->search->search($validated['q'], $address),
        );
    }
}
