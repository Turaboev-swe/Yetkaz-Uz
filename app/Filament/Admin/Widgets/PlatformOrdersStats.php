<?php

namespace App\Filament\Admin\Widgets;

use App\Services\Reporting\OrderStatsService;
use App\Services\Reporting\ReportPeriod;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * /admin Dashboard — oxirgi 30 kun bo'yicha platforma ko'rsatkichlari.
 */
class PlatformOrdersStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $period = ReportPeriod::custom(now()->subDays(29), now());
        $s = app(OrderStatsService::class)->summary($period);
        $trend = app(OrderStatsService::class)->ordersPerDay($period)->pluck('orders')->all();

        return [
            Stat::make('Buyurtmalar (30 kun)', number_format($s['orders'], 0, '.', ' '))
                ->description($s['delivered'].' yetkazilgan · '.$s['cancelled'].' bekor')
                ->chart($trend)
                ->color('primary'),
            Stat::make('Daromad (30 kun)', Money::soms($s['revenue_tiyin']))
                ->description("O'rtacha chek: ".Money::soms($s['avg_check_tiyin'])),
            Stat::make('Faol mijozlar', number_format($s['customers'], 0, '.', ' '))
                ->description('Buyurtma bergan noyob foydalanuvchilar'),
        ];
    }
}
