<?php

namespace App\Filament\Admin\Widgets;

use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * /admin Dashboard — Telegram mijozlari (users) bo'yicha qisqa statistika.
 */
class UsersOverviewStats extends StatsOverviewWidget
{
    protected static ?int $sort = 4;

    protected function getStats(): array
    {
        $today = Carbon::today();
        $monthStart = Carbon::today()->startOfMonth();

        $trend = collect(range(29, 0))
            ->map(fn (int $daysAgo) => Carbon::today()->subDays($daysAgo))
            ->map(fn (Carbon $day) => User::query()->whereDate('created_at', $day)->count())
            ->all();

        $total = User::query()->count();
        $completed = User::query()->where('profile_completed', true)->count();

        return [
            Stat::make('Jami mijozlar', number_format($total, 0, '.', ' '))
                ->description('Oxirgi 30 kunlik ro\'yxatdan o\'tish')
                ->chart($trend)
                ->color('primary'),

            Stat::make('Bugun ro\'yxatdan o\'tgan', number_format(
                User::query()->whereDate('created_at', $today)->count(), 0, '.', ' ',
            )),

            Stat::make('Shu oy ro\'yxatdan o\'tgan', number_format(
                User::query()->where('created_at', '>=', $monthStart)->count(), 0, '.', ' ',
            )),

            Stat::make('Jami /start bosganlar', number_format($total, 0, '.', ' '))
                ->description("Faqat bot bilan aloqa qilgan, profil to'liq emas ham")
                ->color('gray'),

            Stat::make("To'liq ro'yxatdan o'tganlar", number_format($completed, 0, '.', ' '))
                ->description("{$total} dan {$completed} tasi to'liq (ism+telefon+lokatsiya)")
                ->color('success'),
        ];
    }
}
