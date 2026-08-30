<?php

namespace App\Filament\Support;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

/**
 * `work_hours` jsonb ({"mon": [["09:00","23:00"]], ...}) ni tahrirlaydigan Repeater.
 *
 * Repeater'ning ichki holati "qatorlar" ko'rinishida ([{day, from, to}, ...]).
 * DB shakli bilan almashtirish sahifa/resource save-hook'larida `toSchedule()` /
 * `toRows()` orqali qilinadi (Repeater dehydrateStateUsing ishonchsiz).
 */
final class WorkHoursForm
{
    public const DAYS = [
        'mon' => 'Dushanba', 'tue' => 'Seshanba', 'wed' => 'Chorshanba', 'thu' => 'Payshanba',
        'fri' => 'Juma', 'sat' => 'Shanba', 'sun' => 'Yakshanba',
    ];

    public static function make(string $name = 'work_hours'): Repeater
    {
        return Repeater::make($name)
            ->label('Ish vaqti')
            ->schema([
                Select::make('day')->label('Kun')->options(self::DAYS)->required(),
                TextInput::make('from')->label('Ochilish')->placeholder('09:00')
                    ->rule('date_format:H:i')->required(),
                TextInput::make('to')->label('Yopilish')->placeholder('23:00')
                    ->rule('date_format:H:i')->required(),
            ])
            ->columns(3)
            ->addActionLabel('Vaqt oralig`i qo`shish')
            ->defaultItems(0);
    }

    /** {"mon":[["09:00","23:00"]]} -> [{day,from,to}, ...] */
    public static function toRows(mixed $schedule): array
    {
        $rows = [];

        foreach ((array) ($schedule ?? []) as $day => $intervals) {
            foreach ((array) $intervals as $interval) {
                $rows[] = ['day' => $day, 'from' => $interval[0] ?? '', 'to' => $interval[1] ?? ''];
            }
        }

        return $rows;
    }

    /** [{day,from,to}, ...] -> {"mon":[["09:00","23:00"]]} */
    public static function toSchedule(mixed $rows): array
    {
        $schedule = [];

        foreach ((array) ($rows ?? []) as $row) {
            $day = $row['day'] ?? null;
            if (! isset(self::DAYS[$day], $row['from'], $row['to']) || $row['from'] === '' || $row['to'] === '') {
                continue;
            }
            $schedule[$day][] = [$row['from'], $row['to']];
        }

        return $schedule;
    }
}
