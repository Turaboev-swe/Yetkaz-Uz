<?php

namespace App\Services\Ordering;

use App\Enums\DeliveryType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Jobs\NotifyRestaurantOfNewOrder;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Delivery\RestaurantFinder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Buyurtma yaratish: savatni bazaga qarab tekshiradi (narx, mavjudlik),
 * summani/ETA/masofani hisoblaydi, snapshot bilan Order yozadi.
 *
 * Narx MIJOZ savatidan OLINMAYDI — har doim bazadan (manipulyatsiyaga qarshi).
 */
class OrderService
{
    public function __construct(private readonly RestaurantFinder $finder) {}

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
        $eta = $this->etaMinutes($restaurant, $type, $distanceKm);

        $order = DB::transaction(function () use ($user, $restaurant, $address, $type, $lines, $subtotal, $deliveryFee, $eta, $distanceKm, $data) {
            $order = Order::create([
                'order_number' => $this->orderNumber(),
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

        // Restoran egasiga Telegram bildirishnomasi (navbatga).
        NotifyRestaurantOfNewOrder::dispatch($order->id);

        return $order;
    }

    /**
     * @param  array<int, array{product_id:int, qty:int}>  $items
     * @return array<int, array{product_id:int, name:string, price:int, qty:int, note:null}>
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

    /** prep_time + yo'l vaqti (~18 km/soat), 5 daqiqaga yaxlitlangan. Pickup: faqat prep. */
    private function etaMinutes(Restaurant $restaurant, DeliveryType $type, ?float $distanceKm): int
    {
        $prep = (int) $restaurant->avg_prep_time_min;
        $travel = ! $type->isPickup() && $distanceKm !== null
            ? (int) round($distanceKm / 18 * 60)
            : 0;

        return max(15, (int) ceil(($prep + $travel) / 5) * 5);
    }

    private function orderNumber(): string
    {
        do {
            $number = 'YK-'.strtoupper(Str::random(6));
        } while (Order::query()->where('order_number', $number)->exists());

        return $number;
    }
}
