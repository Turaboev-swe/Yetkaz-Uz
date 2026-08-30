<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAddressRequest;
use App\Http\Requests\UpdateAddressRequest;
use App\Http\Resources\AddressResource;
use App\Models\Address;
use App\Services\User\AddressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AddressController extends Controller
{
    public function __construct(private readonly AddressService $addresses) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $list = $request->user()->addresses()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();

        return AddressResource::collection($list);
    }

    public function store(StoreAddressRequest $request): JsonResponse
    {
        $address = $this->addresses->create($request->user(), $request->validated());

        return (new AddressResource($address))->response()->setStatusCode(201);
    }

    public function update(UpdateAddressRequest $request, string $address): AddressResource
    {
        $model = $this->ownedOrFail($request, $address);

        return new AddressResource($this->addresses->update($model, $request->validated()));
    }

    public function destroy(Request $request, string $address): JsonResponse
    {
        $this->addresses->delete($this->ownedOrFail($request, $address));

        return response()->json(status: 204);
    }

    /** Faqat so'rov egasining manzili — aks holda 404. */
    private function ownedOrFail(Request $request, string $id): Address
    {
        return $request->user()->addresses()->findOrFail($id);
    }
}
