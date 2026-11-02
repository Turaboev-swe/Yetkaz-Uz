<?php

namespace App\Services\Dispatch;

use App\Enums\OrderStatus;
use App\Enums\PosType;
use App\Events\OrderDispatchFailed;
use App\Models\Order;
use App\Models\Restaurant;
use Illuminate\Support\Collection;

/**
 * Chek chiqmaganini aniqlash — Reverb xatosiga tayanmasdan.
 *
 * Broadcast muvaffaqiyatli bo'lsa ham (agent o'chiq, printerda qog'oz yo'q, internet yo'q) chek
 * chiqmasligi mumkin. Buyurtma yaratilgandan `print.unprinted_after_seconds` o'tib ham `printed_at`
 * to'lmagan bo'lsa, `dispatch_failed_at` qo'yiladi va oshxona paneliga «Chek chiqmadi» yuboriladi.
 * Keyinroq agent chop etib tasdiqlasa (`confirmPrinted`) belgi o'zi olinadi.
 */
class PrintMonitor
{
    /**
     * @return int yangi belgilangan buyurtmalar soni
     */
    public function flagUnprinted(): int
    {
        $flagged = 0;

        Order::query()
            ->withoutGlobalScopes()
            ->whereNull('printed_at')
            ->whereNull('dispatch_failed_at')
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->where('created_at', '<=', now()->subSeconds((int) config('print.unprinted_after_seconds')))
            ->whereIn('restaurant_id', Restaurant::query()->withPrintAgent()->select('restaurants.id'))
            ->orderBy('id')
            ->each(function (Order $order) use (&$flagged): void {
                $order->forceFill(['dispatch_failed_at' => now()])->saveQuietly();
                $flagged++;

                // Oshxona paneli faqat faol buyurtmalarni ko'rsatadi — o'tgan (yetkazilgan) buyurtma uchun signal yo'q.
                if ($order->status->isActive()) {
                    OrderDispatchFailed::dispatch($order->id, $order->restaurant_id, $order->order_number);
                }
            });

        return $flagged;
    }

    /**
     * Oshxona paneli uchun: har restoran bo'yicha agent holati va chek chiqmagan FAOL buyurtmalar.
     *
     * @param  array<int, int>  $restaurantIds
     * @return array{restaurants: array<int, array<string, mixed>>, failed_order_ids: array<int, int>}
     */
    public function statusFor(array $restaurantIds): array
    {
        /** @var Collection<int, Restaurant> $restaurants */
        $restaurants = Restaurant::query()->whereIn('id', $restaurantIds)->orderBy('name')->get();

        $failed = Order::query()
            ->withoutGlobalScopes()
            // Faqat print agenti sozlangan restoranlar: printeri yo'q restoranda «Chek chiqmadi» bo'lmaydi.
            ->whereIn('restaurant_id', Restaurant::query()->withPrintAgent()->whereIn('restaurants.id', $restaurantIds)->select('restaurants.id'))
            ->whereIn('status', OrderStatus::activeValues())
            ->whereNull('printed_at')
            ->whereNotNull('dispatch_failed_at')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return [
            'restaurants' => $restaurants->map(fn (Restaurant $r): array => [
                'id' => $r->id,
                'name' => $r->name,
                'uses_printer' => $r->usesPrintAgent(),
                'agent_status' => $r->printAgentStatus(),
                'agent_online' => $r->printAgentOnline(),
                'last_seen_at' => $r->print_agent_last_seen_at?->toIso8601String(),
            ])->values()->all(),
            'failed_order_ids' => $failed,
        ];
    }
}
