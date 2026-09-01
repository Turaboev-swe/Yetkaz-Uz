<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load([
            'addresses' => fn ($q) => $q->with('district')->orderByDesc('is_default')->orderBy('id'),
        ]);

        // GET doim 200 — middleware foydalanuvchini yangi yaratgan bo'lsa ham
        // (JsonResource `wasRecentlyCreated` da 201 qaytarardi).
        return (new UserResource($user))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
