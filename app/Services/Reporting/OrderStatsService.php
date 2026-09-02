<?php

namespace App\Services\Reporting;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Buyurtma statistikasi — `/admin` (butun platforma) va `/restaurant` (bitta restoran)
 * hisobotlari uchun. Barcha so'rovlar `DB::table()` orqali (Eloquent global scope'ni
 * chetlab) — `$restaurantId` HAR DOIM aniq uzatiladi.
 *
 * Pul — tiyinда. Vaqtlar bazада UTC; kunlik guruhlash Asia/Tashkent bo'yicha.
 */
class OrderStatsService
{
    private const TZ = 'Asia/Tashkent';

    /**
     * @return array{orders:int, delivered:int, cancelled:int, revenue_tiyin:int, avg_check_tiyin:int, customers:int}
     */
    public function summary(ReportPeriod $period, ?int $restaurantId = null): array
    {
        $row = $this->orders($period, $restaurantId)
            ->selectRaw("
                COUNT(*) AS orders,
                COUNT(*) FILTER (WHERE status = 'delivered') AS delivered,
                COUNT(*) FILTER (WHERE status = 'cancelled') AS cancelled,
                COALESCE(SUM(total) FILTER (WHERE status = 'delivered'), 0) AS revenue_tiyin,
                COUNT(DISTINCT user_id) AS customers
            ")
            ->first();

        $delivered = (int) ($row->delivered ?? 0);
        $revenue = (int) ($row->revenue_tiyin ?? 0);

        return [
            'orders' => (int) ($row->orders ?? 0),
            'delivered' => $delivered,
            'cancelled' => (int) ($row->cancelled ?? 0),
            'revenue_tiyin' => $revenue,
            'avg_check_tiyin' => $delivered > 0 ? intdiv($revenue, $delivered) : 0,
            'customers' => (int) ($row->customers ?? 0),
        ];
    }

    /**
     * Restoranlar reytingi — buyurtma soni bo'yicha.
     *
     * @return Collection<int, array{restaurant_id:int, name:string, orders:int, revenue_tiyin:int, customers:int, cancelled_pct:float}>
     */
    public function topRestaurants(ReportPeriod $period, int $limit = 10): Collection
    {
        return DB::table('orders as o')
            ->join('restaurants as r', 'r.id', '=', 'o.restaurant_id')
            ->whereBetween('o.created_at', [$period->fromUtc(), $period->toUtc()])
            ->groupBy('o.restaurant_id', 'r.name')
            ->orderByDesc('orders')
            ->orderByDesc('revenue_tiyin')
            ->limit($limit)
            ->selectRaw("
                o.restaurant_id,
                r.name,
                COUNT(*) AS orders,
                COALESCE(SUM(o.total) FILTER (WHERE o.status = 'delivered'), 0) AS revenue_tiyin,
                COUNT(DISTINCT o.user_id) AS customers,
                ROUND(100.0 * COUNT(*) FILTER (WHERE o.status = 'cancelled') / NULLIF(COUNT(*), 0), 1) AS cancelled_pct
            ")
            ->get()
            ->map(fn ($r) => [
                'restaurant_id' => (int) $r->restaurant_id,
                'name' => (string) $r->name,
                'orders' => (int) $r->orders,
                'revenue_tiyin' => (int) $r->revenue_tiyin,
                'customers' => (int) $r->customers,
                'cancelled_pct' => (float) ($r->cancelled_pct ?? 0),
            ]);
    }

    /**
     * Oshxona tezligi — restoran bo'yicha. Vaqtlar daqiqада (1 kasr).
     *
     * @return Collection<int, array{restaurant_id:int, name:string, orders:int, avg_accept_min:?float, avg_prep_min:?float, avg_fulfilment_min:?float, cancelled_pct:float, print_failed_pct:float}>
     */
    public function kitchenPerformance(ReportPeriod $period, ?int $restaurantId = null): Collection
    {
        $q = DB::table('orders as o')
            ->join('restaurants as r', 'r.id', '=', 'o.restaurant_id')
            ->leftJoin(DB::raw("(
                SELECT order_id, MIN(changed_at) AS accepted_at
                FROM order_status_history WHERE status = 'accepted' GROUP BY order_id
            ) as a"), 'a.order_id', '=', 'o.id')
            ->whereBetween('o.created_at', [$period->fromUtc(), $period->toUtc()])
            ->groupBy('o.restaurant_id', 'r.name')
            ->orderByDesc('orders')
            ->selectRaw("
                o.restaurant_id,
                r.name,
                COUNT(*) AS orders,
                AVG(EXTRACT(EPOCH FROM (a.accepted_at - o.created_at)))
                    FILTER (WHERE a.accepted_at IS NOT NULL) AS accept_sec,
                AVG(EXTRACT(EPOCH FROM (COALESCE(o.dispatched_at, o.delivered_at) - a.accepted_at)))
                    FILTER (WHERE a.accepted_at IS NOT NULL AND COALESCE(o.dispatched_at, o.delivered_at) IS NOT NULL) AS prep_sec,
                AVG(EXTRACT(EPOCH FROM (o.delivered_at - o.created_at)))
                    FILTER (WHERE o.delivered_at IS NOT NULL) AS fulfilment_sec,
                ROUND(100.0 * COUNT(*) FILTER (WHERE o.status = 'cancelled') / NULLIF(COUNT(*), 0), 1) AS cancelled_pct,
                ROUND(100.0 * COUNT(*) FILTER (WHERE o.dispatch_failed_at IS NOT NULL) / NULLIF(COUNT(*), 0), 1) AS print_failed_pct
            ");

        if ($restaurantId !== null) {
            $q->where('o.restaurant_id', $restaurantId);
        }

        return $q->get()->map(fn ($r) => [
            'restaurant_id' => (int) $r->restaurant_id,
            'name' => (string) $r->name,
            'orders' => (int) $r->orders,
            'avg_accept_min' => $this->toMinutes($r->accept_sec),
            'avg_prep_min' => $this->toMinutes($r->prep_sec),
            'avg_fulfilment_min' => $this->toMinutes($r->fulfilment_sec),
            'cancelled_pct' => (float) ($r->cancelled_pct ?? 0),
            'print_failed_pct' => (float) ($r->print_failed_pct ?? 0),
        ]);
    }

    /**
     * Eng ko'p sotilgan taomlar — `orders.items` jsonb dan.
     *
     * @return Collection<int, array{product_id:int, name:string, qty:int, revenue_tiyin:int}>
     */
    public function topProducts(ReportPeriod $period, ?int $restaurantId = null, int $limit = 10): Collection
    {
        $q = DB::table('orders as o')
            ->crossJoin(DB::raw("LATERAL jsonb_array_elements(COALESCE(o.items, '[]'::jsonb)) AS e"))
            ->whereBetween('o.created_at', [$period->fromUtc(), $period->toUtc()])
            ->groupByRaw("(e->>'product_id')")
            ->orderByDesc('qty')
            ->limit($limit)
            ->selectRaw("
                (e->>'product_id')::int AS product_id,
                MAX(e->>'name') AS name,
                SUM((e->>'qty')::int) AS qty,
                SUM((e->>'qty')::int * (e->>'price')::bigint) AS revenue_tiyin
            ");

        if ($restaurantId !== null) {
            $q->where('o.restaurant_id', $restaurantId);
        }

        return $q->get()->map(fn ($r) => [
            'product_id' => (int) $r->product_id,
            'name' => (string) $r->name,
            'qty' => (int) $r->qty,
            'revenue_tiyin' => (int) $r->revenue_tiyin,
        ]);
    }

    /**
     * Kunlik buyurtmalar (grafik) — oraliqдаги har kun uchun qator, bo'sh kun = 0.
     *
     * @return Collection<int, array{date:string, orders:int}>
     */
    public function ordersPerDay(ReportPeriod $period, ?int $restaurantId = null): Collection
    {
        $counts = $this->orders($period, $restaurantId)
            ->selectRaw("(created_at AT TIME ZONE '".self::TZ."')::date AS d, COUNT(*) AS orders")
            ->groupBy('d')
            ->pluck('orders', 'd');

        $out = collect();
        $cursor = Carbon::parse($period->from->format('Y-m-d'));
        $end = Carbon::parse($period->to->format('Y-m-d'));

        while ($cursor->lte($end)) {
            $key = $cursor->format('Y-m-d');
            $out->push(['date' => $key, 'orders' => (int) ($counts[$key] ?? 0)]);
            $cursor->addDay();
        }

        return $out;
    }

    /** @return Builder */
    private function orders(ReportPeriod $period, ?int $restaurantId)
    {
        $q = DB::table('orders')
            ->whereBetween('created_at', [$period->fromUtc(), $period->toUtc()]);

        if ($restaurantId !== null) {
            $q->where('restaurant_id', $restaurantId);
        }

        return $q;
    }

    private function toMinutes(int|float|string|null $seconds): ?float
    {
        if ($seconds === null) {
            return null;
        }

        return round(((float) $seconds) / 60, 1);
    }
}
