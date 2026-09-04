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
 *
 * "Har kuni" ({day: "everyday"}) — 7 kunga bir xil oraliq. QOIDA: alohida kun
 * qatori doim ustun turadi; "Har kuni" faqat o'z qatori bo'lmagan kunlarni
 * to'ldiradi (shuning uchun "Har kuni" hech qachon alohida kunni buzmaydi).
 * Barcha 7 kun bir xil bo'lsa `toRows()` ularni bitta "Har kuni" qatoriga jamlaydi.
 */
final class WorkHoursForm
{
    public const EVERYDAY = 'everyday';

    public const DAYS = [
        'mon' => 'Dushanba', 'tue' => 'Seshanba', 'wed' => 'Chorshanba', 'thu' => 'Payshanba',
        'fri' => 'Juma', 'sat' => 'Shanba', 'sun' => 'Yakshanba',
    ];

    /** @return array<string, string> "Har kuni" birinchi, keyin kunlar. */
    public static function dayOptions(): array
    {
        return [self::EVERYDAY => 'Har kuni'] + self::DAYS;
    }

    public static function make(string $name = 'work_hours'): Repeater
    {
        return Repeater::make($name)
            ->label('Ish vaqti')
            ->schema([
                Select::make('day')->label('Kun')->options(self::dayOptions())->required()
                    ->helperText('«Har kuni» faqat o‘z qatori bo‘lmagan kunlarga qo‘llanadi.'),
                TextInput::make('from')->label('Ochilish')->placeholder('09:00')
                    ->rule('date_format:H:i')->required(),
                TextInput::make('to')->label('Yopilish')->placeholder('23:00')
                    ->rule('date_format:H:i')->required(),
            ])
            ->columns(3)
            ->addActionLabel('Vaqt oralig`i qo`shish')
            ->defaultItems(0);
    }

    /**
     * {"mon":[["09:00","23:00"]], ...} -> qatorlar.
     * Barcha 7 kun ochiq bo'lsa, eng ko'p uchraydigan oraliq(lar) bitta "Har kuni"
     * qatoriga jamlanadi; qolgan kunlar alohida qator sifatida qoladi.
     */
    public static function toRows(mixed $schedule): array
    {
        $schedule = (array) ($schedule ?? []);

        $signatures = [];
        foreach (array_keys(self::DAYS) as $day) {
            $intervals = array_values(array_filter(
                (array) ($schedule[$day] ?? []),
                fn ($i) => is_array($i) && isset($i[0], $i[1]) && $i[0] !== '' && $i[1] !== '',
            ));
            $signatures[$day] = json_encode($intervals);
        }

        // Jamlash faqat barcha 7 kun ochiq bo'lganда (yopiq kunni "Har kuni" buzmasligi uchun).
        if (! in_array('[]', $signatures, true)) {
            $counts = array_count_values($signatures);
            arsort($counts);
            $dominant = (string) array_key_first($counts);

            if ($counts[$dominant] >= 2) {
                $rows = [];
                foreach ((array) json_decode($dominant, true) as $interval) {
                    $rows[] = ['day' => self::EVERYDAY, 'from' => $interval[0], 'to' => $interval[1]];
                }
                foreach (array_keys(self::DAYS) as $day) {
                    if ($signatures[$day] === $dominant) {
                        continue;
                    }
                    foreach ((array) json_decode($signatures[$day], true) as $interval) {
                        $rows[] = ['day' => $day, 'from' => $interval[0], 'to' => $interval[1]];
                    }
                }

                return $rows;
            }
        }

        // Aks holda — har oraliq o'z qatori (eski xatti-harakat).
        $rows = [];
        foreach ($schedule as $day => $intervals) {
            foreach ((array) $intervals as $interval) {
                $rows[] = ['day' => $day, 'from' => $interval[0] ?? '', 'to' => $interval[1] ?? ''];
            }
        }

        return $rows;
    }

    /**
     * Qatorlar -> {"mon":[["09:00","23:00"]], ...}.
     * "Har kuni" qatorlari — barcha kunlar uchun boshlang'ich; alohida kun qatori
     * o'sha kunni to'liq almashtiradi (o'z oraliqlari bilan).
     */
    public static function toSchedule(mixed $rows): array
    {
        $everyday = [];
        $explicit = [];

        foreach ((array) ($rows ?? []) as $row) {
            $day = $row['day'] ?? null;
            $from = $row['from'] ?? '';
            $to = $row['to'] ?? '';

            if ($from === '' || $to === '') {
                continue;
            }

            if ($day === self::EVERYDAY) {
                $everyday[] = [$from, $to];
            } elseif (isset(self::DAYS[$day])) {
                $explicit[$day][] = [$from, $to];
            }
        }

        $schedule = [];
        foreach (array_keys(self::DAYS) as $day) {
            if (isset($explicit[$day])) {
                $schedule[$day] = $explicit[$day];
            } elseif ($everyday !== []) {
                $schedule[$day] = $everyday;
            }
        }

        return $schedule;
    }
}
