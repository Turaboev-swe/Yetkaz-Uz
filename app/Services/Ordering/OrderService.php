<?php

namespace App\Services\Ordering;

use App\Enums\DeliveryType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Events\OrderPlaced;
use App\Jobs\DispatchOrderJob;
use App\Jobs\NotifyRestaurantOfNewOrder;
use App\Jobs\SendOrderConfirmationToCustomer;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Delivery\RestaurantFinder;
use App\Services\Eta\EtaEstimate;
use App\Services\Eta\EtaEstimator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Buyurtma yaratish: savatni bazaga qarab tekshiradi (narx, mavjudlik),
 * summani/ETA/masofani hisoblaydi, snapshot bilan Order yozadi.
 *
 * Narx MIJOZ savatidan OLINMAYDI — har doim bazadan (manipulyatsiyaga qarshi).
 */
class OrderService
{
    public function __construct(
        private readonly RestaurantFinder $finder,
        private readonly EtaEstimator $eta,
        private readonly OrderNumberGenerator $orderNumbers,
    ) {}

    /**
     * @param  array{restaurant_id:int, delivery_type:string, address_id?:int|null,
     *               payment_method?:string, note?:string|null,
     *               items:array<int, array{product_id:int, qty:int}>}  $data
     */
    public function place(User $user, array $data): Order
    {
        $restaurant = Restaurant::query()->findOrFail($data['restaurant_id']);

        if (! $restaurant->isOpenNow()) {
            throw ValidationException::withMessages(['restaurant' => __('messages.restaurant_closed')]);
        }

        $type = DeliveryType::from($data['delivery_type']);

        $address = null;
        $distanceKm = null;

        if (! $type->isPickup()) {
            $address = $user->addresses()->with('district')->findOrFail($data['address_id']);

            if (! $this->finder->canDeliver($restaurant, $address)) {
                throw ValidationException::withMessages(['address_id' => __('messages.out_of_radius')]);
            }

            $distanceKm = $this->finder->distanceKm($restaurant, $address);
        }

        $lines = $this->resolveLines($restaurant, $data['items']);
        $subtotal = array_sum(array_map(fn ($l) => $l['price'] * $l['qty'], $lines));

        if ($subtotal < (int) $restaurant->min_order_amount) {
            throw ValidationException::withMessages([
                'items' => __('messages.min_order_not_met', [
                    'amount' => number_format(intdiv((int) $restaurant->min_order_amount, 100), 0, '.', ' '),
                ]),
            ]);
        }

        $deliveryFee = $type->isPickup() ? 0 : (int) $restaurant->delivery_fee;
        $maxPrep = max(array_map(fn ($l) => $l['prep'], $lines) ?: [(int) $restaurant->avg_prep_time_min]);
        $eta = $this->eta->estimate($restaurant, $type, $distanceKm, $maxPrep)->minutes;

        $order = DB::transaction(function () use ($user, $restaurant, $address, $type, $lines, $subtotal, $deliveryFee, $eta, $distanceKm, $data) {
            $order = Order::create([
                'order_number' => $this->orderNumbers->generate(),
                'user_id' => $user->id,
                'restaurant_id' => $restaurant->id,
                'address_id' => $address?->id,
                'delivery_type' => $type,
                'items' => $lines,
                'address_snapshot' => $address ? $this->addressSnapshot($address) : null,
                'note' => $data['note'] ?? null,
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'total' => $subtotal + $deliveryFee,
                'payment_method' => PaymentMethod::from($data['payment_method'] ?? 'cash'),
                'payment_status' => PaymentStatus::Pending,
                'status' => OrderStatus::New,
                'eta_minutes' => $eta,
                'distance_km' => $distanceKm,
            ]);

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'status' => OrderStatus::New->value,
                'changed_by' => "user:{$user->id}",
                'changed_at' => now(),
            ]);

            return $order;
        });

        // Oshxona paneli (Reverb) + chek printeri + restoran egasiga Telegram DM +
        // mijozga chek ko'rinishidagi tasdiq. Hammasi navbatга — HTTP javobi kutilmaydi.
        OrderPlaced::dispatch($order->id, $order->restaurant_id);
        DispatchOrderJob::dispatch($order->id);
        NotifyRestaurantOfNewOrder::dispatch($order->id);
        SendOrderConfirmationToCustomer::dispatch($order->id);

        return $order;
    }

    /**
     * Rasmiylashtirish ekrani uchun ETA oldindan ko'rsatiladi (buyurtma yaratilmaydi).
     *
     * @param  array{restaurant_id:int, delivery_type:string, address_id?:int|null,
     *               items?:array<int, array{product_id:int, qty:int}>}  $data
     */
    public function estimateEta(User $user, array $data): EtaEstimate
    {
        $restaurant = Restaurant::query()->findOrFail($data['restaurant_id']);
        $type = DeliveryType::from($data['delivery_type']);

        $distanceKm = null;
        if (! $type->isPickup() && ! empty($data['address_id'])) {
            $address = $user->addresses()->find($data['address_id']);
            if ($address !== null) {
                $distanceKm = $this->finder->distanceKm($restaurant, $address);
            }
        }

        $ids = array_column($data['items'] ?? [], 'product_id');
        $maxPrep = (int) (Product::query()->forRestaurant($restaurant->id)
            ->whereIn('id', $ids)->max('prep_time_min') ?: $restaurant->avg_prep_time_min);

        return $this->eta->estimate($restaurant, $type, $distanceKm, $maxPrep);
    }

    /**
     * @param  array<int, array{product_id:int, qty:int}>  $items
     * @return array<int, array{product_id:int, name:string, price:int, qty:int, prep:int, note:null}>
     */
    private function resolveLines(Restaurant $restaurant, array $items): array
    {
        $qtyById = [];
        foreach ($items as $item) {
            $qtyById[(int) $item['product_id']] = ($qtyById[(int) $item['product_id']] ?? 0) + (int) $item['qty'];
        }

        $products = Product::query()
            ->forRestaurant($restaurant->id)
            ->available()
            ->whereIn('id', array_keys($qtyById))
            ->get();

        if ($products->count() !== count($qtyById)) {
            throw ValidationException::withMessages(['items' => __('messages.cart_item_unavailable')]);
        }

        return $products->map(fn (Product $p) => [
            'product_id' => $p->id,
            'name' => $p->name,
            'price' => (int) $p->price,
            'qty' => (int) $qtyById[$p->id],
            'prep' => (int) $p->prep_time_min,
            'note' => null,
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    private function addressSnapshot($address): array
    {
        return [
            'label' => $address->label,
            'address_text' => $address->address_text,
            'district' => $address->district?->name,
            'lat' => $address->lat,
            'lng' => $address->lng,
            'entrance' => $address->entrance,
            'floor' => $address->floor,
            'apartment' => $address->apartment,
        ];
    }
}
