<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Restaurant;
use App\Models\RestaurantInvoice;
use App\Models\RestaurantPayment;
use App\Services\Billing\BillingCalculator;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * Obunalar ko'rsatkichlari: shu oy kutilayotgan daromad, umumiy qarz, shu oy tushgan to'lovlar,
 * tariflar bo'yicha restoranlar. Dashboard'da (avtomat) va Obunalar sahifasida.
 */
class BillingStats extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected function getStats(): array
    {
        $calc = app(BillingCalculator::class);
        $tz = BillingCalculator::timezone();
        $month = CarbonImmutable::now($tz)->startOfMonth();

        // Davr tarifi (tarix) bilan barcha test bo'lmagan restoranlar — bitta so'rov.
        $rows = $calc->aggregated($month)->where('is_test', false)->get();

        // Shu oy kutilayotgan daromad = fixed (oldindan) shu oy hisoblari + percent'ning joriy jonli hisobi.
        $expected = $rows->sum(fn (Restaurant $r): int => $calc->expectedAmount($r, $month));

        $debt = (int) RestaurantInvoice::query()
            ->where('status', '!=', 'waived')
            ->sum(DB::raw('GREATEST(amount_due - amount_paid, 0)'));

        $received = (int) RestaurantPayment::query()->active()
            ->whereBetween('paid_at', [$month->format('Y-m-d'), $month->endOfMonth()->format('Y-m-d')])
            ->sum('amount');

        $percent = $rows->where('period_plan', 'percent')->count();
        $fixed = $rows->where('period_plan', 'fixed')->count();
        $none = $rows->count() - $percent - $fixed;

        return [
            Stat::make('Shu oy kutilayotgan daromad', Money::soms($expected))
                ->description(BillingCalculator::monthLabel($month).' · fixed hisoblari + foiz (jonli)')
                ->color('primary'),
            Stat::make('Umumiy qarz', Money::soms($debt))
                ->description('Barcha oylar bo‘yicha (kechirilganlarsiz)')
                ->color($debt > 0 ? 'danger' : 'success'),
            Stat::make('Shu oy tushgan to‘lovlar', Money::soms($received))
                ->description(BillingCalculator::monthLabel($month))
                ->color('success'),
            Stat::make('Tariflar', "Foiz: {$percent} · Qat‘iy: {$fixed}")
                ->description("Tarifsiz restoranlar: {$none}")
                ->color($none > 0 ? 'warning' : 'gray'),
        ];
    }
}
