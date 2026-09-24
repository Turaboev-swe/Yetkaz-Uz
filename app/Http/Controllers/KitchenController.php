<?php

namespace App\Http\Controllers;

use App\Enums\CourierType;
use App\Enums\OrderStatus;
use App\Http\Resources\KitchenOrderResource;
use App\Models\Order;
use App\Models\Staff;
use App\Services\Ordering\OrderStatusService;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Oshxona paneli (/kitchen) — session (staff guard) bilan himoyalangan.
 * Faqat o'z restorani buyurtmalari.
 */
class KitchenController extends Controller
{
    public function __construct(private readonly OrderStatusService $status) {}

    /** Sahifa (React ilova). */
    public function page()
    {
        return view('kitchen', ['staff' => $this->staff()]);
    }

    /**
     * POST /kitchen/push/subscribe — brauzer `PushSubscription.toJSON()` ni
     * saqlaydi (yoki yangilaydi). Bir xil `endpoint` boshqa xodimda bo'lsa,
     * paket uni o'chirib shu xodimga qayta bog'laydi (`updatePushSubscription`).
     */
    public function subscribePush(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
        ]);

        $this->staff()->updatePushSubscription(
            endpoint: $data['endpoint'],
            key: $data['keys']['p256dh'],
            token: $data['keys']['auth'],
        );

        return response()->json(['data' => ['subscribed' => true]], Response::HTTP_CREATED);
    }

    /** DELETE /kitchen/push/subscribe — "Bildirishnomani o'chirish". */
    public function unsubscribePush(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string']]);

        $this->staff()->deletePushSubscription($data['endpoint']);

        return response()->json(['data' => ['subscribed' => false]]);
    }

    /** GET /kitchen/orders — faol buyurtmalar (eng eskisi birinchi). */
    public function orders(): AnonymousResourceCollection
    {
        $orders = Order::query()
            ->withoutGlobalScopes()
            ->where('restaurant_id', $this->staff()->restaurant_id)
            ->whereIn('status', OrderStatus::activeValues())
            ->with('user')
            ->orderBy('created_at')
            ->get();

        return KitchenOrderResource::collection($orders);
    }

    /** GET /kitchen/couriers — "Yo'lga chiqdi" dropdown'i uchun o'z restorani xodimlari. */
    public function couriers(): JsonResponse
    {
        $list = Staff::query()
            ->where('restaurant_id', $this->staff()->restaurant_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'phone']);

        return response()->json(['data' => $list]);
    }

    /**
     * PATCH /kitchen/orders/{order}/advance — statusni keyingi bosqichga.
     *
     * "Yo'lga chiqdi"da kuryer turi ixtiyoriy: `courier_type=own_staff`
     * (+ ixtiyoriy `courier_staff_id`) yoki `courier_type=taxi`
     * (+ majburiy `courier_phone`, `courier_name` serverda "Royal Taxi"
     * qattiq belgilanadi — mijoz/frontend buni o'zgartira olmaydi).
     */
    public function advance(Request $request, Order $order): JsonResponse
    {
        $staff = $this->staff();

        abort_unless($order->restaurant_id === $staff->restaurant_id, Response::HTTP_FORBIDDEN);

        $data = $request->validate([
            'courier_type' => ['nullable', Rule::enum(CourierType::class)],
            'courier_staff_id' => [
                'nullable', 'integer',
                Rule::exists('staff', 'id')->where(fn ($q) => $q
                    ->where('restaurant_id', $order->restaurant_id)
                    ->where('is_active', true)),
            ],
            'courier_phone' => ['nullable', 'string', 'max:32'],
        ]);

        $fill = $this->courierFill($data);

        $this->status->advance($order, "kitchen:{$staff->id}", $fill);

        return (new KitchenOrderResource($order->fresh('user')))->response();
    }

    /** @param  array<string, mixed>  $data
     * @return array<string, mixed> */
    private function courierFill(array $data): array
    {
        // Eski frontend (courier_type yubormaydi) — courier_staff_id bo'lsa
        // o'z-o'zidan "own_staff" ekani aniq, moslikni saqlaymiz.
        $type = $data['courier_type'] ?? (! empty($data['courier_staff_id']) ? CourierType::OwnStaff->value : null);

        if ($type === CourierType::Taxi->value) {
            $phone = Phone::normalizeUzbek((string) ($data['courier_phone'] ?? ''));

            if ($phone === null) {
                throw ValidationException::withMessages([
                    'courier_phone' => "Telefon raqami +998 bilan boshlanib, to'g'ri uzunlikda bo'lishi kerak.",
                ]);
            }

            return [
                'courier_type' => CourierType::Taxi->value,
                'courier_name' => 'Royal Taxi',
                'courier_phone' => $phone,
                'courier_staff_id' => null,
            ];
        }

        if ($type === CourierType::OwnStaff->value) {
            $fill = ['courier_type' => CourierType::OwnStaff->value];

            if (! empty($data['courier_staff_id'])) {
                $courier = Staff::findOrFail($data['courier_staff_id']);
                $fill += [
                    'courier_staff_id' => $courier->id,
                    'courier_name' => $courier->name,
                    'courier_phone' => $courier->phone,   // tanlangan paytdagi snapshot
                ];
            }

            return $fill;
        }

        return [];
    }

    /**
     * PATCH /kitchen/orders/{order}/cancel — buyurtmani bekor qiladi.
     * Bot bilan bir xil OrderStatusService::cancel() ni chaqiradi.
     */
    public function cancel(Request $request, Order $order): JsonResponse
    {
        $staff = $this->staff();

        abort_unless($order->restaurant_id === $staff->restaurant_id, Response::HTTP_FORBIDDEN);

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        // ValidationException (noto'g'ri holat) -> 422, xabar bilan.
        $this->status->cancel($order, $data['reason'], "kitchen:{$staff->id}");

        return (new KitchenOrderResource($order->fresh('user')))->response();
    }

    private function staff(): Staff
    {
        $staff = auth('staff')->user();

        abort_unless($staff instanceof Staff && $staff->canManageKitchen(), Response::HTTP_FORBIDDEN);

        return $staff;
    }
}
