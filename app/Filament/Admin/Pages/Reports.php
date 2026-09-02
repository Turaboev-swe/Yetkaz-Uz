<?php

namespace App\Filament\Admin\Pages;

use App\Services\Reporting\OrderStatsService;
use App\Services\Reporting\ReportPeriod;
use App\Support\CsvResponse;
use App\Support\Duration;
use App\Support\Money;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Platforma hisobotlari: qaysi restoranga ko'p buyurtma tushadi, oshxona tezligi,
 * eng ko'p sotilgan taomlar. Sana oralig'i bo'yicha filtrlanadi.
 */
class Reports extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Hisobotlar';

    protected static ?string $title = 'Hisobotlar';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.admin.pages.reports';

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill(['preset' => 'month']);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Grid::make(3)->schema([
                    Select::make('preset')
                        ->label('Davr')
                        ->options([
                            'today' => 'Bugun',
                            'week' => 'Shu hafta',
                            'month' => 'Shu oy',
                            'quarter' => 'Shu chorak',
                            'all' => 'Butun davr',
                            'custom' => 'Boshqa oraliq…',
                        ])
                        ->selectablePlaceholder(false)
                        ->live(),
                    DatePicker::make('from')->label('Dan')->native(false)
                        ->visible(fn ($get) => $get('preset') === 'custom')->live(),
                    DatePicker::make('to')->label('Gacha')->native(false)
                        ->visible(fn ($get) => $get('preset') === 'custom')->live(),
                ]),
            ])
            ->statePath('data');
    }

    public function period(): ReportPeriod
    {
        $preset = $this->data['preset'] ?? 'month';

        if ($preset === 'custom' && ! empty($this->data['from']) && ! empty($this->data['to'])) {
            return ReportPeriod::custom($this->data['from'], $this->data['to']);
        }

        return ReportPeriod::preset($preset === 'custom' ? 'month' : $preset);
    }

    protected function stats(): OrderStatsService
    {
        return app(OrderStatsService::class);
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $period = $this->period();
        $stats = $this->stats();

        return [
            'periodLabel' => $period->label(),
            'summary' => $stats->summary($period),
            'topRestaurants' => $stats->topRestaurants($period)->map(fn ($r) => [
                $r['name'],
                number_format($r['orders'], 0, '.', ' '),
                Money::soms($r['revenue_tiyin']),
                number_format($r['customers'], 0, '.', ' '),
                $r['cancelled_pct'].'%',
            ])->all(),
            'kitchen' => $stats->kitchenPerformance($period)->map(fn ($r) => [
                $r['name'],
                number_format($r['orders'], 0, '.', ' '),
                Duration::human($r['avg_accept_min']),
                Duration::human($r['avg_prep_min']),
                Duration::human($r['avg_fulfilment_min']),
                $r['cancelled_pct'].'%',
                $r['print_failed_pct'].'%',
            ])->all(),
            'topProducts' => $stats->topProducts($period)->map(fn ($r) => [
                $r['name'],
                number_format($r['qty'], 0, '.', ' ').' dona',
                Money::soms($r['revenue_tiyin']),
            ])->all(),
        ];
    }

    public function exportRestaurants(): StreamedResponse
    {
        $rows = $this->stats()->topRestaurants($this->period(), 100)->map(fn ($r) => [
            $r['name'], $r['orders'], Money::toSoms($r['revenue_tiyin']), $r['customers'], $r['cancelled_pct'],
        ]);

        return CsvResponse::stream('top-restoranlar.csv',
            ['Restoran', 'Buyurtmalar', "Daromad (so'm)", 'Mijozlar', 'Bekor %'], $rows);
    }

    public function exportKitchen(): StreamedResponse
    {
        $rows = $this->stats()->kitchenPerformance($this->period())->map(fn ($r) => [
            $r['name'], $r['orders'], $r['avg_accept_min'], $r['avg_prep_min'],
            $r['avg_fulfilment_min'], $r['cancelled_pct'], $r['print_failed_pct'],
        ]);

        return CsvResponse::stream('oshxona-tezligi.csv',
            ['Restoran', 'Buyurtmalar', 'Qabul (daq)', 'Tayyorlash (daq)', 'Yetkazish (daq)', 'Bekor %', 'Chek xato %'], $rows);
    }

    public function exportProducts(): StreamedResponse
    {
        $rows = $this->stats()->topProducts($this->period(), null, 100)->map(fn ($r) => [
            $r['name'], $r['qty'], Money::toSoms($r['revenue_tiyin']),
        ]);

        return CsvResponse::stream('top-taomlar.csv', ['Taom', 'Sotildi', "Daromad (so'm)"], $rows);
    }
}
