<?php

namespace App\Services\Billing;

use App\Enums\BillingPlan;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantInvoice;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Restoran obunasini hisoblash. DB'ga YOZMAYDI (faqat o'qiydi) — hisob-kitoblar
 * yozilishi InvoiceService'da.
 *
 * Qoidalar:
 *  - oy: 1-kundan oxirigacha, Asia/Tashkent bo'yicha (UTC saqlangan `delivered_at` shunga o'giriladi)
 *  - faqat `delivered` buyurtmalar, `delivered_at` shu oyda (bekor qilinganlar va boshqa holatlar kirmaydi)
 *  - baza: `subtotal − discount_restaurant_amount` — taom summasi, restoran amalda olgan qismi.
 *    Yetkazish haqi KIRMAYDI; platforma ulushidagi chegirma ayirilmaydi (uni platforma qoplaydi)
 *  - percent: baza × stavka (bp) / 10 000, BUTUN SO'MGA yaxlitlanadi (to'lovlar so'mda
 *    kiritiladi — aks holda hisob bir necha tiyinga "qisman" bo'lib qolardi); fixed: oylik to'lov,
 *    `billing_started_at` oy o'rtasiga tushsa birinchi oy proratsiya (faol kunlar / oydagi kunlar)
 *  - hisob-kitobni qayta hisoblash (calculateForInvoice) restoranning joriy tarifini EMAS,
 *    hisobdagi snapshot'ni ishlatadi
 *  - `billing_started_at` bo'lsa, o'sha sanadan oldingi (Toshkent) buyurtmalar hisobga olinmaydi
 *  - test restoran (`is_test`), tarifsiz restoran va `billing.start_date` dan oldingi oylar hisoblanmaydi
 */
class BillingCalculator
{
    private const MONTHS = [
        1 => 'Yanvar', 2 => 'Fevral', 3 => 'Mart', 4 => 'Aprel', 5 => 'May', 6 => 'Iyun',
        7 => 'Iyul', 8 => 'Avgust', 9 => 'Sentabr', 10 => 'Oktabr', 11 => 'Noyabr', 12 => 'Dekabr',
    ];

    public static function timezone(): string
    {
        return (string) config('app.display_timezone');
    }

    /** Davrning (oyning) birinchi kuni 00:00, Asia/Tashkent. `Y-m`, `Y-m-d` yoki Carbon. */
    public static function monthStart(CarbonInterface|string $period): CarbonImmutable
    {
        $tz = self::timezone();

        $local = is_string($period)
            ? CarbonImmutable::parse(strlen($period) === 7 ? $period.'-01' : $period, $tz)
            : CarbonImmutable::instance($period)->setTimezone($tz);

        return $local->startOfMonth()->startOfDay();
    }

    public static function monthLabel(CarbonInterface|string $period): string
    {
        $start = self::monthStart($period);

        return self::MONTHS[$start->month].' '.$start->year;
    }

    /** Hisob-kitob boshlanadigan birinchi oy (Toshkent). */
    public static function startMonth(): CarbonImmutable
    {
        return self::monthStart((string) config('billing.start_date'));
    }

    /**
     * Davr bo'yicha buyurtmalar oynasi [from, to) — UTC. `to` — keyingi oyning boshi.
     * `billing_started_at` oynani qisqartiradi (Toshkent sanasining boshidan).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function window(CarbonInterface|string|null $startedAt, CarbonInterface|string $period): array
    {
        $start = self::monthStart($period);
        $from = $start;

        if ($startedAt !== null) {
            $startedLocal = self::localDate($startedAt);
            $from = $startedLocal->greaterThan($from) ? $startedLocal : $from;
        }

        return [$from->utc(), $start->addMonth()->utc()];
    }

    /** `Y-m-d` sana (yoki Carbon `date` cast) -> Toshkent kunining boshi. */
    private static function localDate(CarbonInterface|string $date): CarbonImmutable
    {
        $ymd = $date instanceof CarbonInterface ? $date->format('Y-m-d') : substr($date, 0, 10);

        return CarbonImmutable::parse($ymd, self::timezone())->startOfDay();
    }

    /**
     * Fixed tarif proratsiyasi uchun: davrdagi faol kunlar va oydagi kunlar.
     * `billing_started_at` shu oyning o'rtasiga tushsa, boshlangan kun ham hisobga kiradi
     * (2026-11-16 -> 15/30); oldingi oylarda yoki sana yo'q bo'lsa oy to'liq.
     *
     * @return array{0: int, 1: int}
     */
    public static function activeDays(CarbonInterface|string|null $startedAt, CarbonInterface|string $period): array
    {
        $start = self::monthStart($period);
        $total = $start->daysInMonth;

        if ($startedAt === null) {
            return [$total, $total];
        }

        $started = self::localDate($startedAt);

        if ($started->lessThanOrEqualTo($start) || $started->greaterThanOrEqualTo($start->addMonth())) {
            return [$total, $total];
        }

        return [$total - $started->day + 1, $total];
    }

    /** Shu restoran shu oy uchun hisoblanadimi (tarif bor, test emas, oy boshlangan). */
    public function isBillable(Restaurant $restaurant, CarbonInterface|string $period): bool
    {
        if ($restaurant->billing_plan === null || $restaurant->is_test) {
            return false;
        }

        $start = self::monthStart($period);

        if ($start->lessThan(self::startMonth())) {
            return false;
        }

        // Tarif keyingi oydan boshlanadigan bo'lsa — bu oy hisoblanmaydi.
        return $restaurant->billing_started_at === null
            || self::monthStart($restaurant->billing_started_at->format('Y-m-d'))->lessThanOrEqualTo($start);
    }

    /**
     * Sof funksiya: to'lanadigan summa (tiyin, butun so'm).
     * Fixed: `$activeDays < $daysInMonth` bo'lsa birinchi oy proratsiya qilinadi
     * (oylik × faol kunlar / oydagi kunlar, butun so'mga yarim yuqoriga yaxlitlanadi).
     */
    public static function amountDue(
        ?BillingPlan $plan,
        int $rateBp,
        int $fixedFee,
        int $ordersTotal,
        ?int $activeDays = null,
        ?int $daysInMonth = null,
    ): int {
        return match ($plan) {
            // base(tiyin) × bp / 10 000 = tiyin; so'mga yarim-yuqoriga yaxlit: / 1 000 000 so'm.
            BillingPlan::Percent => intdiv($ordersTotal * $rateBp + 500_000, 1_000_000) * 100,
            BillingPlan::Fixed => self::prorate($fixedFee, $activeDays, $daysInMonth),
            null => 0,
        };
    }

    private static function prorate(int $fee, ?int $activeDays, ?int $daysInMonth): int
    {
        if ($activeDays === null || $daysInMonth === null || $daysInMonth <= 0 || $activeDays >= $daysInMonth) {
            return $fee;
        }

        // fee(tiyin) × active / days -> butun so'm (÷100), yarim yuqoriga.
        return intdiv($fee * max(0, $activeDays) + $daysInMonth * 50, $daysInMonth * 100) * 100;
    }

    /**
     * Restoran + shu oy bo'yicha yetkazilgan buyurtmalar agregati (`billing_orders_count`,
     * `billing_orders_total`) — bitta so'rovda barcha restoranlar uchun (saralash/filtr mumkin).
     * Hisoblash shu yagona so'rovga asoslanadi (calculate ham, jadval ham, widget ham).
     *
     * @return Builder<Restaurant>
     */
    public function aggregated(CarbonInterface|string $period): Builder
    {
        $start = self::monthStart($period)->utc();
        $end = $start->setTimezone(self::timezone())->addMonth()->utc();
        $tz = self::timezone();

        $sub = fn (string $expr) => DB::table('orders')
            ->selectRaw($expr)
            ->whereColumn('orders.restaurant_id', 'restaurants.id')
            ->where('orders.status', OrderStatus::Delivered->value)
            ->where('orders.delivered_at', '<', $end->format('Y-m-d H:i:s'))
            ->whereRaw(
                "orders.delivered_at >= GREATEST(?::timestamp, COALESCE((restaurants.billing_started_at::timestamp AT TIME ZONE '{$tz}' AT TIME ZONE 'UTC'), ?::timestamp))",
                [$start->format('Y-m-d H:i:s'), $start->format('Y-m-d H:i:s')],
            );

        return Restaurant::query()
            ->select('restaurants.*')
            ->addSelect([
                'billing_orders_count' => $sub('COUNT(*)'),
                'billing_orders_total' => $sub('COALESCE(SUM(orders.subtotal - orders.discount_restaurant_amount), 0)'),
            ]);
    }

    /** `aggregated()` qatoridan (yoki oddiy Restaurant'dan — agregatsiz) natija. */
    public function resultFor(Restaurant $row, CarbonInterface|string $period): BillingResult
    {
        $count = (int) ($row->billing_orders_count ?? 0);
        $total = (int) ($row->billing_orders_total ?? 0);
        $billable = $this->isBillable($row, $period);
        [$active, $days] = self::activeDays($row->billing_started_at, $period);

        return new BillingResult(
            plan: $row->billing_plan,
            rateBp: (int) $row->commission_rate_bp,
            fixedFee: (int) $row->monthly_fee,
            ordersCount: $count,
            ordersTotal: $total,
            amountDue: $billable ? self::amountDue($row->billing_plan, (int) $row->commission_rate_bp, (int) $row->monthly_fee, $total, $active, $days) : 0,
            billable: $billable,
        );
    }

    /** Restoranning JORIY tarifi bilan hisoblash. */
    public function calculate(Restaurant $restaurant, CarbonInterface|string $period): BillingResult
    {
        $row = $this->aggregated($period)->whereKey($restaurant->getKey())->first() ?? $restaurant;

        return $this->resultFor($row, $period);
    }

    /**
     * Hisob-kitobni o'zining SNAPSHOT tarifi (plan, rate_bp, fixed_fee, billing_started_at) bilan
     * qayta hisoblash. Restoranning joriy tarifiga qaramaydi. Faqat buyurtmalar soni, aylanma
     * va to'lanadigan summa o'zgaradi.
     */
    public function calculateForInvoice(RestaurantInvoice $invoice): BillingResult
    {
        $totals = $this->ordersQuery($invoice->restaurant_id, $invoice->period, $invoice->billing_started_at)
            ->toBase()
            ->selectRaw('COUNT(*) AS c, COALESCE(SUM(subtotal - discount_restaurant_amount), 0) AS t')
            ->first();

        $count = (int) ($totals->c ?? 0);
        $total = (int) ($totals->t ?? 0);
        [$active, $days] = self::activeDays($invoice->billing_started_at, $invoice->period);

        return new BillingResult(
            plan: $invoice->plan,
            rateBp: (int) $invoice->rate_bp,
            fixedFee: (int) $invoice->fixed_fee,
            ordersCount: $count,
            ordersTotal: $total,
            amountDue: self::amountDue($invoice->plan, (int) $invoice->rate_bp, (int) $invoice->fixed_fee, $total, $active, $days),
            billable: true,
        );
    }

    /**
     * Hisobga kiradigan buyurtmalar (invoice ko'rinishi uchun). `calculate()` bilan bir xil oyna.
     *
     * @return Builder<Order>
     */
    public function ordersQuery(int $restaurantId, CarbonInterface|string $period, CarbonInterface|string|null $startedAt = null): Builder
    {
        [$from, $to] = self::window($startedAt, $period);

        return Order::withoutGlobalScopes()
            ->where('restaurant_id', $restaurantId)
            ->where('status', OrderStatus::Delivered->value)
            ->where('delivered_at', '>=', $from->format('Y-m-d H:i:s'))
            ->where('delivered_at', '<', $to->format('Y-m-d H:i:s'));
    }
}
