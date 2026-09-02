<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_formats_tiyin_as_soms(): void
    {
        $this->assertSame("0 so'm", Money::soms(0));
        $this->assertSame("0 so'm", Money::soms(null));
        $this->assertSame("120 so'm", Money::soms(12_000));
        $this->assertSame("1 234 567 so'm", Money::soms(123_456_700));
    }

    public function test_to_soms_rounds_down_to_whole_som(): void
    {
        $this->assertSame(1234, Money::toSoms(123_450));
        $this->assertSame(0, Money::toSoms(99));
    }

    public function test_amount_has_no_unit(): void
    {
        $this->assertSame('1 000', Money::amount(100_000));
    }
}
