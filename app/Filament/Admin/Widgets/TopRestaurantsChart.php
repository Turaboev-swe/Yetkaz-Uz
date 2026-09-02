<?php

namespace App\Filament\Admin\Widgets;

use App\Services\Reporting\OrderStatsService;
use App\Services\Reporting\ReportPeriod;
use Filament\Widgets\ChartWidget;

/**
 * /admin Dashboard — oxirgi 30 kunда eng ko'p buyurtma olgan restoranlar.
 */
class TopRestaurantsChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected static ?string $heading = 'Top restoranlar — buyurtma soni (30 kun)';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $top = app(OrderStatsService::class)
            ->topRestaurants(ReportPeriod::custom(now()->subDays(29), now()), 6);

        return [
            'datasets' => [[
                'label' => 'Buyurtmalar',
                'data' => $top->pluck('orders')->all(),
                'backgroundColor' => 'rgba(16, 185, 129, 0.7)',
            ]],
            'labels' => $top->pluck('name')->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
        ];
    }
}
