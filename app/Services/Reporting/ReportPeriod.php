<?php

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Hisobot sana oralig'i. Chegaralar `config('app.display_timezone')`
 * (Asia/Tashkent) bo'yicha quriladi — "Bugun" aynan Toshkent kalendar kuni
 * (00:00-23:59). Baza ustunlari UTC bo'lgani uchun so'rovda `fromUtc()` /
 * `toUtc()` ishlatiladi — bu METODLAR haqiqiy konvertatsiya qiladi (`->from`/
 * `->to` allaqachon Toshkent zonasida, `APP_TIMEZONE=UTC`ga bog'liq emas).
 */
final class ReportPeriod
{
    private function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $key,
    ) {}

    /** today | week | month | quarter | all */
    public static function preset(string $key): self
    {
        $tz = config('app.display_timezone');
        $now = CarbonImmutable::now($tz);

        [$from, $to] = match ($key) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'week' => [$now->startOfWeek(), $now->endOfWeek()],
            'quarter' => [$now->startOfQuarter(), $now->endOfQuarter()],
            'all' => [CarbonImmutable::create(2020, 1, 1, 0, 0, 0, $tz)->startOfDay(), $now->endOfDay()],
            default => [$now->startOfMonth(), $now->endOfMonth()], // month
        };

        return new self($from, $to, in_array($key, ['today', 'week', 'month', 'quarter', 'all'], true) ? $key : 'month');
    }

    /**
     * `$from`/`$to` — Toshkent kalendar sanasi (masalan admin DatePicker'idan
     * "2026-09-21"). Aniq shu zonada tahlil qilinishi shart, aks holda
     * `startOfDay()`/`endOfDay()` noto'g'ri kun chegarasini beradi (REPORT-1).
     *
     * Carbon obyekti (masalan vidjetlardagi `now()`, u UTC'da) — avval Toshkent
     * zonasiga O'GIRILADI: `parse()` obyektning zonasini o'zgartirmaydi, aks
     * holda Toshkent 00:00–04:59 da "bugun" UTC bo'yicha kecha bo'lib qolardi (REPORT-3).
     */
    public static function custom(Carbon|CarbonImmutable|string $from, Carbon|CarbonImmutable|string $to): self
    {
        $tz = config('app.display_timezone');
        $local = fn (Carbon|CarbonImmutable|string $d): CarbonImmutable => is_string($d)
            ? CarbonImmutable::parse($d, $tz)
            : CarbonImmutable::instance($d)->setTimezone($tz);

        return new self($local($from)->startOfDay(), $local($to)->endOfDay(), 'custom');
    }

    /**
     * SQL: UTC'da saqlangan (`timestamp without time zone`) ustunning Toshkent
     * kalendar sanasi. Avval `AT TIME ZONE 'UTC'` — qiymat UTC ekanini aytadi,
     * keyin Toshkentga o'giradi. Faqat `AT TIME ZONE 'Asia/Tashkent'` qiymatni
     * "allaqachon Toshkent vaqti" deb talqin qilib sanani surardi (REPORT-3).
     */
    public static function localDateSql(string $column): string
    {
        return "({$column} AT TIME ZONE 'UTC' AT TIME ZONE '".config('app.display_timezone')."')::date";
    }

    /**
     * Oraliqdagi har bir Toshkent kalendar kuni ('Y-m-d') — grafik o'qi.
     *
     * @return list<string>
     */
    public function dates(): array
    {
        $out = [];
        for ($d = $this->from->startOfDay(); $d->lte($this->to); $d = $d->addDay()) {
            $out[] = $d->format('Y-m-d');
        }

        return $out;
    }

    /**
     * So'nggi N kun — kalendar kuni emas, "hozir"dan orqaga harakatlanuvchi
     * oyna (`to` doim joriy vaqt). "Faol mijoz" kabi "so'nggi N kun ichida"
     * ta'riflar uchun — presetlar (bugun/hafta/oy) kalendar chegarasi bilan
     * ishlaydi, bu esa aniq 24*N soat oldin.
     */
    public static function trailing(int $days): self
    {
        $tz = config('app.display_timezone');
        $now = CarbonImmutable::now($tz);

        return new self($now->subDays($days), $now, 'trailing');
    }

    public function fromUtc(): CarbonImmutable
    {
        return $this->from->utc();
    }

    public function toUtc(): CarbonImmutable
    {
        return $this->to->utc();
    }

    /** Kunlar soni (grafik uchun teng oraliq). */
    public function days(): int
    {
        return (int) $this->from->startOfDay()->diffInDays($this->to->startOfDay()) + 1;
    }

    public function label(): string
    {
        return match ($this->key) {
            'today' => 'Bugun',
            'week' => 'Shu hafta',
            'quarter' => 'Shu chorak',
            'all' => 'Butun davr',
            'custom' => $this->from->format('d.m.Y').' – '.$this->to->format('d.m.Y'),
            'trailing' => 'So\'nggi '.$this->from->diffInDays($this->to).' kun',
            default => 'Shu oy',
        };
    }
}
