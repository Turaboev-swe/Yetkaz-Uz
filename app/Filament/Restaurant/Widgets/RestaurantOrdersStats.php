<?php

namespace App\Filament\Restaurant\Widgets;

use App\Services\Reporting\OrderStatsService;
use App\Services\Reporting\ReportPeriod;
use App\Support\Duration;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * /restaurant Dashboard — FAQAT o'z restorani, oxirgi 30 kun.
 */
class RestaurantOrdersStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $rid = (int) auth('staff')->user()->restaurant_id;
        $period = ReportPeriod::custom(now()->subDays(29), now());
        $svc = app(OrderStatsService::class);

        $s = $svc->summary($period, $rid);
        $trend = $svc->ordersPerDay($period, $rid)->pluck('orders')->all();
        $kitchen = $svc->kitchenPerformance($period, $rid)->first();

        return [
            Stat::make('Buyurtmalar (30 kun)', number_format($s['orders'], 0, '.', ' '))
                ->description($s['delivered'].' yetkazilgan · '.$s['cancelled'].' bekor')
                ->chart($trend)
                ->color('primary'),
            Stat::make('Daromad (30 kun)', Money::soms($s['revenue_tiyin']))
                ->description("O'rtacha chek: ".Money::soms($s['avg_check_tiyin'])),
            Stat::make("O'rtacha tayyorlash", Duration::human($kitchen['avg_prep_min'] ?? null))
                ->description('Tasdiqlangandan yo\'lga chiqqungacha'),
        ];
    }
}
