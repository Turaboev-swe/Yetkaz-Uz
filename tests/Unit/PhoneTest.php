<?php

namespace Tests\Unit;

use App\Support\Phone;
use PHPUnit\Framework\TestCase;

class PhoneTest extends TestCase
{
    public function test_bare_nine_digit_number_gets_998_prefix(): void
    {
        $this->assertSame('+998901112233', Phone::normalizeUzbek('901112233'));
    }

    public function test_already_correct_number_is_unchanged(): void
    {
        $this->assertSame('+998901112233', Phone::normalizeUzbek('+998901112233'));
    }

    public function test_number_with_998_but_no_plus_gets_the_plus(): void
    {
        $this->assertSame('+998901112233', Phone::normalizeUzbek('998901112233'));
    }

    public function test_local_format_with_leading_zero_is_converted(): void
    {
        $this->assertSame('+998901112233', Phone::normalizeUzbek('0901112233'));
    }

    public function test_too_short_number_is_rejected(): void
    {
        $this->assertNull(Phone::normalizeUzbek('12345'));
    }

    public function test_non_numeric_input_is_rejected(): void
    {
        $this->assertNull(Phone::normalizeUzbek('abcdefghi'));
    }

    public function test_formatting_characters_are_stripped(): void
    {
        $this->assertSame('+998901112233', Phone::normalizeUzbek('+998 90 111 22 33'));
        $this->assertSame('+998901112233', Phone::normalizeUzbek('90-111-22-33'));
    }

    public function test_998_prefixed_number_with_wrong_length_is_rejected(): void
    {
        $this->assertNull(Phone::normalizeUzbek('+99890111223')); // 11 raqam, 12 emas
        $this->assertNull(Phone::normalizeUzbek('+9989011122334')); // 13 raqam
    }

    public function test_empty_string_is_rejected(): void
    {
        $this->assertNull(Phone::normalizeUzbek(''));
    }
}
