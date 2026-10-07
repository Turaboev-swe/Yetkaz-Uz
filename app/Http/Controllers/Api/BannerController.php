<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BannerResource;
use App\Services\Marketing\BannerFeed;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BannerController extends Controller
{
    public function __construct(private readonly BannerFeed $feed) {}

    /** GET /api/banners — bosh sahifa karuseli: faol, muddati ichidagi bannerlar. */
    public function index(Request $request): AnonymousResourceCollection
    {
        return BannerResource::collection($this->feed->current(viewer: $request->user()));
    }
}
