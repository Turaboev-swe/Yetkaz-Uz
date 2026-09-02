<?php

namespace Tests\Unit;

use App\Support\Duration;
use PHPUnit\Framework\TestCase;

class DurationTest extends TestCase
{
    public function test_human_readable_durations(): void
    {
        $this->assertSame('—', Duration::human(null));
        $this->assertSame('<1 daq', Duration::human(0.4));
        $this->assertSame('12.5 daq', Duration::human(12.5));
        $this->assertSame('1 soat', Duration::human(60));
        $this->assertSame('1 soat 30 daq', Duration::human(90));
        $this->assertSame('1.3 kun', Duration::human(1874));
    }
}
