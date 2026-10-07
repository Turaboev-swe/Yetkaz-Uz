<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\OrderStatus;
use App\Models\User;
use App\Services\Reporting\ReportPeriod;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * /admin Dashboard — Telegram mijozlari (users) bo'yicha qisqa statistika.
 */
class UsersOverviewStats extends StatsOverviewWidget
{
    protected static ?int $sort = 4;

    protected function getStats(): array
    {
        // Kun/oy chegaralari — Toshkent kalendari (ReportPeriod), UTC emas (REPORT-3).
        $today = ReportPeriod::preset('today');
        $month = ReportPeriod::preset('month');
        $last30 = ReportPeriod::custom(now()->subDays(29), now());

        $perDay = User::query()
            ->whereBetween('created_at', [$last30->fromUtc(), $last30->toUtc()])
            ->selectRaw(ReportPeriod::localDateSql('created_at').' AS d, COUNT(*) AS n')
            ->groupBy('d')
            ->pluck('n', 'd');
        $trend = array_map(fn (string $d) => (int) ($perDay[$d] ?? 0), $last30->dates());

        $total = User::query()->count();
        $completed = User::query()->where('profile_completed', true)->count();

        return [
            Stat::make('Jami mijozlar', number_format($total, 0, '.', ' '))
                ->description('Oxirgi 30 kunlik ro\'yxatdan o\'tish')
                ->chart($trend)
                ->color('primary'),

            Stat::make('Bugun ro\'yxatdan o\'tgan', number_format(
                User::query()->whereBetween('created_at', [$today->fromUtc(), $today->toUtc()])->count(), 0, '.', ' ',
            )),

            Stat::make('Shu oy ro\'yxatdan o\'tgan', number_format(
                User::query()->where('created_at', '>=', $month->fromUtc())->count(), 0, '.', ' ',
            )),

            Stat::make('Jami /start bosganlar', number_format($total, 0, '.', ' '))
                ->description("Faqat bot bilan aloqa qilgan, profil to'liq emas ham")
                ->color('gray'),

            Stat::make("To'liq ro'yxatdan o'tganlar", number_format($completed, 0, '.', ' '))
                ->description("{$total} dan {$completed} tasi to'liq (ism+telefon+lokatsiya)")
                ->color('success'),

            $this->activeCustomersStat(),

            Stat::make('Qaytgan mijozlar', number_format(User::query()->returning()->count(), 0, '.', ' '))
                ->description('2 va undan ko\'p yetkazilgan buyurtma')
                ->color('success'),
        ];
    }

    /**
     * Faol mijozlar (so'nggi 30 kun, `delivered` buyurtma bo'yicha) + oldingi
     * 30 kunga nisbatan o'zgarish. Ikkala son ham bitta EXISTS so'rovi bilan
     * (whereHas) — N+1 yo'q.
     */
    private function activeCustomersStat(): Stat
    {
        $now = User::query()->activeSince(30)->count();

        $previous = User::query()
            ->whereHas('orders', fn ($q) => $q
                ->excludingTest()
                ->where('status', OrderStatus::Delivered->value)
                ->where('delivered_at', '>=', ReportPeriod::trailing(60)->fromUtc())
                ->where('delivered_at', '<', ReportPeriod::trailing(30)->fromUtc()))
            ->count();

        $diff = $now - $previous;
        $description = match (true) {
            $diff > 0 => "+{$diff} oldingi 30 kunga nisbatan",
            $diff < 0 => "{$diff} oldingi 30 kunga nisbatan",
            default => "O'zgarishsiz (oldingi 30 kun)",
        };

        return Stat::make('Faol mijozlar (30 kun)', number_format($now, 0, '.', ' '))
            ->description($description)
            ->descriptionIcon(match (true) {
                $diff > 0 => 'heroicon-m-arrow-trending-up',
                $diff < 0 => 'heroicon-m-arrow-trending-down',
                default => 'heroicon-m-minus',
            })
            ->color(match (true) {
                $diff > 0 => 'success',
                $diff < 0 => 'danger',
                default => 'gray',
            });
    }
}
