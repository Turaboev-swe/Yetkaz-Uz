<?php

namespace App\Filament\Admin\Widgets;

use App\Services\Reporting\OrderStatsService;
use App\Services\Reporting\ReportPeriod;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * /admin Dashboard — oxirgi 30 kun, kunlik buyurtmalar holat bo'yicha
 * (ustma-ust ustun: yetkazilgan / bekor qilingan / jarayonda). Ustun balandligi = kunlik jami.
 */
class OrdersTrendChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected static ?string $heading = 'Buyurtmalar dinamikasi (30 kun)';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $series = app(OrderStatsService::class)
            ->ordersPerDay(ReportPeriod::custom(now()->subDays(29), now()));

        return [
            'datasets' => [
                ['label' => 'Yetkazilgan', 'data' => $series->pluck('delivered')->all(), 'backgroundColor' => '#22C55E'],
                ['label' => 'Bekor qilingan', 'data' => $series->pluck('cancelled')->all(), 'backgroundColor' => '#EF4444'],
                ['label' => 'Jarayonda', 'data' => $series->pluck('in_progress')->all(), 'backgroundColor' => '#F5A623'],
            ],
            'labels' => $series->map(fn ($r) => Carbon::parse($r['date'])->format('d.m'))->all(),
        ];
    }

    protected function getOptions(): array|RawJs|null
    {
        return RawJs::make(<<<'JS'
        {
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: true },
                tooltip: {
                    callbacks: {
                        footer: (items) => 'Jami: ' + items.reduce((sum, i) => sum + i.parsed.y, 0),
                    },
                },
            },
            scales: {
                x: { stacked: true },
                y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } },
            },
        }
        JS);
    }
}
