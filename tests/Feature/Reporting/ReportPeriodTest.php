<?php

namespace Tests\Feature\Reporting;

use App\Services\Reporting\ReportPeriod;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * REPORT-1: "Bugun"/"Shu hafta"/... chegaralari Toshkent kalendar kuniga mos
 * kelishi kerak (APP_TIMEZONE=UTC bo'lsa ham), so'rov uchun esa to'g'ri UTC'ga
 * o'girilishi kerak (Toshkent 00:00 = UTC 19:00, oldingi kun).
 */
class ReportPeriodTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_today_preset_spans_the_full_tashkent_calendar_day_in_utc(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-21 12:00:00', 'Asia/Tashkent'));

        $period = ReportPeriod::preset('today');

        $this->assertSame('2026-09-20 19:00:00', $period->fromUtc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-21 18:59:59', $period->toUtc()->format('Y-m-d H:i:s'));
    }

    public function test_week_preset_starts_monday_tashkent_time(): void
    {
        // 2026-09-21 — dushanba (Toshkentda); "hozir" shu haftaning chorshanbasi.
        Carbon::setTestNow(Carbon::parse('2026-09-23 12:00:00', 'Asia/Tashkent'));

        $period = ReportPeriod::preset('week');

        $this->assertSame('2026-09-20 19:00:00', $period->fromUtc()->format('Y-m-d H:i:s'));
    }

    public function test_month_preset_starts_on_the_1st_tashkent_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', 'Asia/Tashkent'));

        $period = ReportPeriod::preset('month');

        $this->assertSame('2026-08-31 19:00:00', $period->fromUtc()->format('Y-m-d H:i:s'));
    }

    public function test_all_preset_starts_2020_01_01_tashkent_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-21 12:00:00', 'Asia/Tashkent'));

        $period = ReportPeriod::preset('all');

        $this->assertSame('2019-12-31 19:00:00', $period->fromUtc()->format('Y-m-d H:i:s'));
        $this->assertSame('all', $period->key);
    }

    public function test_custom_period_uses_tashkent_calendar_days(): void
    {
        $period = ReportPeriod::custom('2026-09-01', '2026-09-01');

        $this->assertSame('2026-08-31 19:00:00', $period->fromUtc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-01 18:59:59', $period->toUtc()->format('Y-m-d H:i:s'));
    }
}
