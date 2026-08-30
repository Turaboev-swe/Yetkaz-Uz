<?php

namespace Tests\Unit;

use App\Support\WorkHours;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class WorkHoursTest extends TestCase
{
    private function at(string $datetime): CarbonImmutable
    {
        return CarbonImmutable::parse($datetime, 'Asia/Tashkent');
    }

    public function test_open_within_a_simple_interval(): void
    {
        $wh = WorkHours::from(['wed' => [['09:00', '23:00']]]);

        $this->assertTrue($wh->isOpenAt($this->at('2026-09-02 12:00')));   // wed
        $this->assertFalse($wh->isOpenAt($this->at('2026-09-02 08:59')));
        $this->assertFalse($wh->isOpenAt($this->at('2026-09-02 23:00')));  // end exclusive
    }

    public function test_closed_on_a_day_with_no_schedule(): void
    {
        $wh = WorkHours::from(['mon' => [['09:00', '23:00']]]);

        $this->assertFalse($wh->isOpenAt($this->at('2026-09-02 12:00'))); // wed
    }

    public function test_multiple_intervals_lunch_break(): void
    {
        $wh = WorkHours::from(['wed' => [['09:00', '14:00'], ['17:00', '23:00']]]);

        $this->assertTrue($wh->isOpenAt($this->at('2026-09-02 10:00')));
        $this->assertFalse($wh->isOpenAt($this->at('2026-09-02 15:30')));
        $this->assertTrue($wh->isOpenAt($this->at('2026-09-02 18:00')));
    }

    public function test_overnight_interval(): void
    {
        $wh = WorkHours::from(['wed' => [['18:00', '02:00']]]);

        $this->assertTrue($wh->isOpenAt($this->at('2026-09-02 23:30')));
        $this->assertTrue($wh->isOpenAt($this->at('2026-09-02 01:00')));
        $this->assertFalse($wh->isOpenAt($this->at('2026-09-02 03:00')));
        $this->assertFalse($wh->isOpenAt($this->at('2026-09-02 17:00')));
    }

    public function test_empty_schedule(): void
    {
        $wh = WorkHours::from(null);

        $this->assertTrue($wh->isEmpty());
        $this->assertFalse($wh->isOpenAt($this->at('2026-09-02 12:00')));
    }
}
