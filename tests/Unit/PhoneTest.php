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

    public function test_leading_eight_is_stripped_only_for_ten_digit_numbers(): void
    {
        $this->assertSame('+998901112233', Phone::normalizeUzbek('8901112233'));
        $this->assertSame('+998901112233', Phone::normalizeUzbek('8 (90) 111-22-33'));
    }

    public function test_nine_digit_numbers_starting_with_88_are_not_broken(): void
    {
        $this->assertSame('+998881112233', Phone::normalizeUzbek('881112233'));
        $this->assertSame('+998881112233', Phone::normalizeUzbek('+998 88 111 22 33'));
        $this->assertSame('+998881112233', Phone::normalizeUzbek('8881112233')); // 10 xonali: 8 prefiks, qolgani 88...
    }

    public function test_eight_prefix_with_wrong_length_is_rejected(): void
    {
        $this->assertNull(Phone::normalizeUzbek('89011122')); // 8 xonali
        $this->assertNull(Phone::normalizeUzbek('89011122334')); // 11 xonali
    }

    public function test_normalized_result_is_plus_998_and_nine_digits(): void
    {
        foreach (['901112233', '0901112233', '8901112233', '998901112233', '+998 90 111 22 33', '90-111-22-33'] as $raw) {
            $this->assertMatchesRegularExpression('/^\+998\d{9}$/', (string) Phone::normalizeUzbek($raw), $raw);
        }
    }
}
