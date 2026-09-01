<?php

namespace App\Services\Dispatch;

/**
 * Minimal ESC/POS buyruq quruvchi (termal chek printerlari uchun).
 * 80mm qog'oz — Font A da ~48 belgi; xavfsizlik uchun 42 ishlatamiz.
 *
 * Bir vaqtda ikkitasini quradi: printer uchun bayt oqim (`raw()`) va
 * simulate rejimi uchun o'qiladigan matn (`plain()`).
 */
class EscPos
{
    public const WIDTH = 42;

    private const ESC = "\x1b";

    private const GS = "\x1d";

    private string $buffer = '';

    private string $plain = '';

    public function init(): self
    {
        $this->buffer .= self::ESC.'@';
        $this->buffer .= self::ESC.'t'."\x06";

        return $this;
    }

    public function align(string $mode): self
    {
        $n = match ($mode) {
            'center' => "\x01",
            'right' => "\x02",
            default => "\x00",
        };
        $this->buffer .= self::ESC.'a'.$n;

        return $this;
    }

    public function bold(bool $on): self
    {
        $this->buffer .= self::ESC.'E'.($on ? "\x01" : "\x00");

        return $this;
    }

    /** $w, $h: 0..7 (0 = oddiy). */
    public function size(int $w = 0, int $h = 0): self
    {
        $this->buffer .= self::GS.'!'.chr((($w & 7) << 4) | ($h & 7));

        return $this;
    }

    public function text(string $line): self
    {
        $line = $this->ascii($line);
        $this->buffer .= $line."\n";
        $this->plain .= $line."\n";

        return $this;
    }

    public function rule(string $ch = '-'): self
    {
        return $this->text(str_repeat($ch, self::WIDTH));
    }

    /** "Chap ..... O'ng" — bitta qatorda ikki tomon. */
    public function columns(string $left, string $right): self
    {
        $left = $this->ascii($left);
        $right = $this->ascii($right);
        $gap = max(1, self::WIDTH - mb_strlen($left) - mb_strlen($right));

        return $this->text($left.str_repeat(' ', $gap).$right);
    }

    public function feed(int $lines = 1): self
    {
        $this->buffer .= str_repeat("\n", $lines);
        $this->plain .= str_repeat("\n", $lines);

        return $this;
    }

    public function cut(): self
    {
        $this->buffer .= self::GS.'V'."\x42\x00"; // partial cut with feed

        return $this;
    }

    public function raw(): string
    {
        return $this->buffer;
    }

    public function plain(): string
    {
        return rtrim($this->plain)."\n";
    }

    /** O'zbek/rus belgilarini ASCII ga (printer WPC1252 da to'g'ri chiqmasligi mumkin). */
    private function ascii(string $s): string
    {
        return strtr($s, [
            'ʻ' => "'", 'ʼ' => "'", '‘' => "'", '’' => "'", '“' => '"', '”' => '"',
            '–' => '-', '—' => '-', '№' => 'N', '×' => 'x', "\u{00A0}" => ' ',
        ]);
    }
}
