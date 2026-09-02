<?php

namespace App\Filament\Restaurant\Widgets;

use App\Services\Reporting\OrderStatsService;
use App\Services\Reporting\ReportPeriod;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * /restaurant Dashboard — o'z restorani kunlik buyurtma soni (30 kun).
 */
class RestaurantOrdersTrendChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected static ?string $heading = 'Buyurtmalar dinamikasi (30 kun)';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $rid = (int) auth('staff')->user()->restaurant_id;
        $series = app(OrderStatsService::class)
            ->ordersPerDay(ReportPeriod::custom(now()->subDays(29), now()), $rid);

        return [
            'datasets' => [[
                'label' => 'Buyurtmalar',
                'data' => $series->pluck('orders')->all(),
                'borderColor' => 'rgb(16, 185, 129)',
                'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                'fill' => true,
                'tension' => 0.3,
            ]],
            'labels' => $series->map(fn ($r) => Carbon::parse($r['date'])->format('d.m'))->all(),
        ];
    }
}
