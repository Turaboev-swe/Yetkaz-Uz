<?php

namespace App\Filament\Restaurant\Pages;

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
 * Restoran egasi uchun hisobot — FAQAT o'z restorani. `restaurant_id` Service'ga
 * aniq uzatiladi (raw SQL global scope'ni chetlab o'tadi).
 */
class Reports extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Hisobotlar';

    protected static ?string $title = 'Hisobotlar';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.restaurant.pages.reports';

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

    protected function restaurantId(): int
    {
        return (int) auth('staff')->user()->restaurant_id;
    }

    protected function stats(): OrderStatsService
    {
        return app(OrderStatsService::class);
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $period = $this->period();
        $rid = $this->restaurantId();
        $stats = $this->stats();

        $kitchen = $stats->kitchenPerformance($period, $rid)->first();

        return [
            'periodLabel' => $period->label(),
            'summary' => $stats->summary($period, $rid),
            'kitchen' => $kitchen ? [
                Duration::human($kitchen['avg_accept_min']),
                Duration::human($kitchen['avg_prep_min']),
                Duration::human($kitchen['avg_fulfilment_min']),
                $kitchen['cancelled_pct'].'%',
                $kitchen['print_failed_pct'].'%',
            ] : null,
            'topProducts' => $stats->topProducts($period, $rid)->map(fn ($r) => [
                $r['name'],
                number_format($r['qty'], 0, '.', ' ').' dona',
                Money::soms($r['revenue_tiyin']),
            ])->all(),
        ];
    }

    public function exportProducts(): StreamedResponse
    {
        $rows = $this->stats()->topProducts($this->period(), $this->restaurantId(), 100)->map(fn ($r) => [
            $r['name'], $r['qty'], Money::toSoms($r['revenue_tiyin']),
        ]);

        return CsvResponse::stream('taomlar-hisoboti.csv', ['Taom', 'Sotildi', "Daromad (so'm)"], $rows);
    }
}
