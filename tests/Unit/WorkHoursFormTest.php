<?php

namespace Tests\Unit;

use App\Filament\Support\WorkHoursForm;
use PHPUnit\Framework\TestCase;

class WorkHoursFormTest extends TestCase
{
    private const ALL_DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public function test_everyday_row_fills_all_seven_days(): void
    {
        $schedule = WorkHoursForm::toSchedule([
            ['day' => 'everyday', 'from' => '09:00', 'to' => '23:00'],
        ]);

        $this->assertSame(self::ALL_DAYS, array_keys($schedule));
        foreach ($schedule as $intervals) {
            $this->assertSame([['09:00', '23:00']], $intervals);
        }
    }

    public function test_explicit_day_row_overrides_everyday_for_that_day_only(): void
    {
        $schedule = WorkHoursForm::toSchedule([
            ['day' => 'everyday', 'from' => '09:00', 'to' => '23:00'],
            ['day' => 'sun', 'from' => '10:00', 'to' => '14:00'],
        ]);

        $this->assertSame([['10:00', '14:00']], $schedule['sun']);
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat'] as $day) {
            $this->assertSame([['09:00', '23:00']], $schedule[$day], "«{$day}» «Har kuni» qiymatida qolishi kerak");
        }
    }

    public function test_multiple_everyday_rows_become_a_split_shift_every_day(): void
    {
        $schedule = WorkHoursForm::toSchedule([
            ['day' => 'everyday', 'from' => '09:00', 'to' => '14:00'],
            ['day' => 'everyday', 'from' => '17:00', 'to' => '23:00'],
        ]);

        $this->assertSame([['09:00', '14:00'], ['17:00', '23:00']], $schedule['mon']);
        $this->assertSame([['09:00', '14:00'], ['17:00', '23:00']], $schedule['sun']);
    }

    public function test_empty_time_rows_are_ignored(): void
    {
        $this->assertSame([], WorkHoursForm::toSchedule([
            ['day' => 'everyday', 'from' => '', 'to' => ''],
            ['day' => 'mon', 'from' => '09:00', 'to' => ''],
        ]));
    }

    public function test_rows_collapse_to_one_everyday_row_when_all_days_identical(): void
    {
        $schedule = array_fill_keys(self::ALL_DAYS, [['09:00', '23:00']]);

        $rows = WorkHoursForm::toRows($schedule);

        $this->assertSame([['day' => 'everyday', 'from' => '09:00', 'to' => '23:00']], $rows);
    }

    public function test_rows_collapse_majority_and_keep_the_odd_day_separate(): void
    {
        $schedule = array_fill_keys(self::ALL_DAYS, [['09:00', '23:00']]);
        $schedule['sun'] = [['10:00', '14:00']];

        $rows = WorkHoursForm::toRows($schedule);

        $this->assertContains(['day' => 'everyday', 'from' => '09:00', 'to' => '23:00'], $rows);
        $this->assertContains(['day' => 'sun', 'from' => '10:00', 'to' => '14:00'], $rows);
        $this->assertCount(2, $rows);
    }

    public function test_rows_do_not_collapse_when_a_day_is_closed(): void
    {
        $schedule = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat'], [['09:00', '23:00']]);
        // sun yopiq

        $rows = WorkHoursForm::toRows($schedule);

        $this->assertNotContains('everyday', array_column($rows, 'day'));
        $this->assertCount(6, $rows);
    }

    public function test_collapsed_rows_round_trip_back_to_the_same_schedule(): void
    {
        $schedule = array_fill_keys(self::ALL_DAYS, [['09:00', '23:00']]);
        $schedule['sat'] = [['10:00', '14:00'], ['18:00', '23:00']];

        $this->assertSame(
            $schedule,
            WorkHoursForm::toSchedule(WorkHoursForm::toRows($schedule)),
        );
    }
}
