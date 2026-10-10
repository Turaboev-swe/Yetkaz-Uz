<?php

namespace App\Services\Reporting;

use App\Enums\OrderStatus;
use App\Models\Restaurant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Buyurtma statistikasi — `/admin` (butun platforma) va `/restaurant` (bitta restoran)
 * hisobotlari uchun. Barcha so'rovlar `DB::table()` orqali (Eloquent global scope'ni
 * chetlab) — `$restaurantId` HAR DOIM aniq uzatiladi.
 *
 * Pul — tiyinда. Vaqtlar bazада UTC; kunlik guruhlash Asia/Tashkent bo'yicha.
 *
 * DAROMAD (restoran daromadi, qaror bo'yicha) = mijoz to'lagan summa (total) +
 * platforma qoplaydigan chegirma (discount_platform_amount). Ya'ni daromaddan
 * faqat RESTORAN qoplaydigan qism kamayadi; ulush 0% bo'lsa daromad chegirmasiz
 * summaga teng. Faqat yetkazilgan buyurtmalar. Chegirma taqsimoti buyurtmadagi
 * snapshot'dan — promo_codes'dan emas.
 *
 * Test restoran (restaurants.is_test) buyurtmalari platforma hisobotlariga
 * KIRMAYDI ($restaurantId = null yoki topRestaurants). Bitta restoran
 * so'ralganda esa — o'sha restoranning o'z ma'lumoti (scopeRestaurant()).
 */
class OrderStatsService
{
    /** SQL: restoran daromadi (yetkazilganlar). `$o` — jadval taxallusi prefiksi (masalan 'o.'). */
    private static function revenueSql(string $o = ''): string
    {
        return "COALESCE(SUM({$o}total + {$o}discount_platform_amount) FILTER (WHERE {$o}status = 'delivered'), 0)";
    }

    /**
     * `avg_check_tiyin` = daromad / yetkazilgan (restoranning bir buyurtmadan
     * o'rtacha daromadi — Daromad bilan bir xil ta'rif).
     *
     * @return array{orders:int, delivered:int, cancelled:int, revenue_tiyin:int, avg_check_tiyin:int, customers:int}
     */
    public function summary(ReportPeriod $period, ?int $restaurantId = null): array
    {
        $row = $this->orders($period, $restaurantId)
            ->selectRaw("
                COUNT(*) AS orders,
                COUNT(*) FILTER (WHERE status = 'delivered') AS delivered,
                COUNT(*) FILTER (WHERE status = 'cancelled') AS cancelled,
                ".self::revenueSql().' AS revenue_tiyin,
                COUNT(DISTINCT user_id) AS customers
            ')
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
            ->whereNotIn('o.restaurant_id', Restaurant::testIdsQuery())
            ->whereBetween('o.created_at', [$period->fromUtc(), $period->toUtc()])
            ->groupBy('o.restaurant_id', 'r.name')
            ->orderByDesc('orders')
            ->orderByDesc('revenue_tiyin')
            ->limit($limit)
            ->selectRaw('
                o.restaurant_id,
                r.name,
                COUNT(*) AS orders,
                '.self::revenueSql('o.')." AS revenue_tiyin,
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
     * Platforma chegirmalari — har restoranga platforma qancha qoplashi kerak
     * (restoranlar bilan hisob-kitob): chegirmali buyurtmalar soni, jami
     * chegirma, restoran qoplagani, platforma qoplaydigani. Platforma qarzi
     * bo'yicha kamayish tartibida.
     *
     * Faqat `delivered` buyurtmalar — bekor qilinganда chegirma xarajati
     * haqiqatda sodir bo'lmagan (daromad bilan bir xil qoida). Summalar
     * buyurtmadagi SNAPSHOT'dan (discount_*_amount) — kodning hozirgi ulushidan emas.
     *
     * @return Collection<int, array{restaurant_id:int, name:string, orders:int, discount_tiyin:int, restaurant_amount_tiyin:int, platform_amount_tiyin:int}>
     */
    public function platformDiscounts(ReportPeriod $period, ?int $restaurantId = null): Collection
    {
        $q = DB::table('orders as o')
            ->join('restaurants as r', 'r.id', '=', 'o.restaurant_id')
            ->where('o.discount_amount', '>', 0)
            ->where('o.status', OrderStatus::Delivered->value)
            ->whereBetween('o.created_at', [$period->fromUtc(), $period->toUtc()])
            ->groupBy('o.restaurant_id', 'r.name')
            ->orderByDesc('platform_amount_tiyin')
            ->orderBy('r.name')
            ->selectRaw('
                o.restaurant_id,
                r.name,
                COUNT(*) AS orders,
                SUM(o.discount_amount) AS discount_tiyin,
                SUM(o.discount_restaurant_amount) AS restaurant_amount_tiyin,
                SUM(o.discount_platform_amount) AS platform_amount_tiyin
            ');

        $this->scopeRestaurant($q, $restaurantId, 'o.restaurant_id');

        return $q->get()->map(fn ($r) => [
            'restaurant_id' => (int) $r->restaurant_id,
            'name' => (string) $r->name,
            'orders' => (int) $r->orders,
            'discount_tiyin' => (int) $r->discount_tiyin,
            'restaurant_amount_tiyin' => (int) $r->restaurant_amount_tiyin,
            'platform_amount_tiyin' => (int) $r->platform_amount_tiyin,
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

        $this->scopeRestaurant($q, $restaurantId, 'o.restaurant_id');

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
     * Eng ko'p sotilgan taomlar — `orders.items` jsonb dan. Faqat `delivered`
     * buyurtmalar — `summary()`/`topRestaurants()` bilan bir xil ta'rif
     * (REPORT-2: bekor qilingan/yakunlanmagan buyurtma "sotilgan" hisoblanmaydi).
     *
     * @return Collection<int, array{product_id:int, name:string, qty:int, revenue_tiyin:int}>
     */
    public function topProducts(ReportPeriod $period, ?int $restaurantId = null, int $limit = 10): Collection
    {
        $q = DB::table('orders as o')
            ->crossJoin(DB::raw("LATERAL jsonb_array_elements(COALESCE(o.items, '[]'::jsonb)) AS e"))
            ->whereBetween('o.created_at', [$period->fromUtc(), $period->toUtc()])
            ->where('o.status', OrderStatus::Delivered->value)
            ->groupByRaw("(e->>'product_id')")
            ->orderByDesc('qty')
            ->limit($limit)
            ->selectRaw("
                (e->>'product_id')::int AS product_id,
                MAX(e->>'name') AS name,
                SUM((e->>'qty')::int) AS qty,
                SUM((e->>'qty')::int * (e->>'price')::bigint) AS revenue_tiyin
            ");

        $this->scopeRestaurant($q, $restaurantId, 'o.restaurant_id');

        return $q->get()->map(fn ($r) => [
            'product_id' => (int) $r->product_id,
            'name' => (string) $r->name,
            'qty' => (int) $r->qty,
            'revenue_tiyin' => (int) $r->revenue_tiyin,
        ]);
    }

    /**
     * Kunlik buyurtmalar (grafik) — oraliqдаги har Toshkent kalendar kuni uchun
     * qator, bo'sh kun = 0. Kun chegarasi summary() bilan bir xil (ReportPeriod).
     *
     * Holat bo'yicha ajratilgan (bitta so'rovda): delivered + cancelled +
     * in_progress (qolgan barcha statuslar) = orders.
     *
     * @return Collection<int, array{date:string, orders:int, delivered:int, cancelled:int, in_progress:int}>
     */
    public function ordersPerDay(ReportPeriod $period, ?int $restaurantId = null): Collection
    {
        $rows = $this->orders($period, $restaurantId)
            ->selectRaw(ReportPeriod::localDateSql('created_at')." AS d,
                COUNT(*) AS orders,
                COUNT(*) FILTER (WHERE status = 'delivered') AS delivered,
                COUNT(*) FILTER (WHERE status = 'cancelled') AS cancelled")
            ->groupBy('d')
            ->get()
            ->keyBy('d');

        return collect($period->dates())->map(function (string $date) use ($rows) {
            $r = $rows[$date] ?? null;
            $orders = (int) ($r->orders ?? 0);
            $delivered = (int) ($r->delivered ?? 0);
            $cancelled = (int) ($r->cancelled ?? 0);

            return [
                'date' => $date,
                'orders' => $orders,
                'delivered' => $delivered,
                'cancelled' => $cancelled,
                'in_progress' => $orders - $delivered - $cancelled,
            ];
        });
    }

    /** @return Builder */
    private function orders(ReportPeriod $period, ?int $restaurantId)
    {
        $q = DB::table('orders')
            ->whereBetween('created_at', [$period->fromUtc(), $period->toUtc()]);

        $this->scopeRestaurant($q, $restaurantId, 'restaurant_id');

        return $q;
    }

    /**
     * Bitta restoran so'ralsa — faqat uning buyurtmalari (test restoran o'z
     * panelida o'z statistikasini ko'radi). Platforma bo'yicha (null) — test
     * restoran (is_test) buyurtmalari CHIQARILADI: ular hisobotga kirmaydi.
     */
    private function scopeRestaurant(Builder $q, ?int $restaurantId, string $column): void
    {
        if ($restaurantId !== null) {
            $q->where($column, $restaurantId);
        } else {
            $q->whereNotIn($column, Restaurant::testIdsQuery());
        }
    }

    private function toMinutes(int|float|string|null $seconds): ?float
    {
        if ($seconds === null) {
            return null;
        }

        return round(((float) $seconds) / 60, 1);
    }
}
