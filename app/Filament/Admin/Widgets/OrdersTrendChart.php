<?php

namespace App\Filament\Admin\Widgets;

use App\Services\Reporting\OrderStatsService;
use App\Services\Reporting\ReportPeriod;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * /admin Dashboard — oxirgi 30 kun kunlik buyurtma soni.
 */
class OrdersTrendChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected static ?string $heading = 'Buyurtmalar dinamikasi (30 kun)';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $series = app(OrderStatsService::class)
            ->ordersPerDay(ReportPeriod::custom(now()->subDays(29), now()));

        return [
            'datasets' => [[
                'label' => 'Buyurtmalar',
                'data' => $series->pluck('orders')->all(),
                'borderColor' => 'rgb(245, 158, 11)',
                'backgroundColor' => 'rgba(245, 158, 11, 0.1)',
                'fill' => true,
                'tension' => 0.3,
            ]],
            'labels' => $series->map(fn ($r) => Carbon::parse($r['date'])->format('d.m'))->all(),
        ];
    }
}
