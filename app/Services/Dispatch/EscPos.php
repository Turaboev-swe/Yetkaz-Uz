<?php

namespace App\Services\Dispatch;

/**
 * Minimal ESC/POS buyruq quruvchi (termal chek printerlari uchun).
 *
 * Qog'oz kengligi restoran sozlamasi: 80 mm — 42 belgi (Font A da ~48, xavfsizlik uchun 42),
 * 58 mm — 32 belgi. Kodlash jadvali (`ESC t n`) ham restoran sozlamasi.
 *
 * Chekka FAQAT ASCII yoziladi: kirill harflar lotinga transliteratsiya qilinadi (o'qilishi uchun),
 * emoji va boshqa belgilar olib tashlanadi — printerning kodlash jadvaliga bog'liq "iflos" belgilar chiqmaydi.
 *
 * Bir vaqtda ikkitasini quradi: printer uchun bayt oqim (`raw()`) va
 * simulate rejimi uchun o'qiladigan matn (`plain()`).
 */
class EscPos
{
    /** 80 mm qog'oz (standart). */
    public const WIDTH = 42;

    /** 58 mm qog'oz. */
    public const WIDTH_58 = 32;

    /** ESC t n: 16 = WPC1252 (Epson-mos printerlar, jumladan Xprinter). */
    public const DEFAULT_CODEPAGE = 16;

    private const ESC = "\x1b";

    private const GS = "\x1d";

    /** Kirill -> o'zbek lotin imlosi (ж=j, х=x, ш=sh, й=y, ы=i). */
    private const CYRILLIC = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo', 'ж' => 'j',
        'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o',
        'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'x', 'ц' => 'ts',
        'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sh', 'ъ' => '', 'ы' => 'i', 'ь' => '', 'э' => 'e', 'ю' => 'yu',
        'я' => 'ya',
        // O'zbek kirill
        'ў' => "o'", 'ғ' => "g'", 'қ' => 'q', 'ҳ' => 'h',
    ];

    private string $buffer = '';

    private string $plain = '';

    public function __construct(
        private readonly int $width = self::WIDTH,
        private readonly int $codepage = self::DEFAULT_CODEPAGE,
    ) {}

    public function width(): int
    {
        return $this->width;
    }

    public function init(): self
    {
        $this->buffer .= self::ESC.'@';
        $this->buffer .= self::ESC.'t'.chr(max(0, min(255, $this->codepage)));

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
        $line = self::ascii($line);
        $this->buffer .= $line."\n";
        $this->plain .= $line."\n";

        return $this;
    }

    public function rule(string $ch = '-'): self
    {
        return $this->text(str_repeat($ch, $this->width));
    }

    /** "Chap ..... O'ng" — bitta qatorda ikki tomon; sig'masa chap tomon qisqartiriladi (qator kenglikdan oshmaydi). */
    public function columns(string $left, string $right): self
    {
        $left = self::ascii($left);
        $right = mb_substr(self::ascii($right), 0, max(1, $this->width - 2));

        $room = $this->width - mb_strlen($right) - 1;
        if (mb_strlen($left) > $room) {
            $left = mb_substr($left, 0, max(0, $room));
        }

        $gap = max(1, $this->width - mb_strlen($left) - mb_strlen($right));

        return $this->text($left.str_repeat(' ', $gap).$right);
    }

    /**
     * Matnni qator kengligiga bo'ladi (so'z chegarasida; juda uzun so'z majburan bo'linadi).
     * `$firstWidth` — birinchi qator sig'imi (oldida prefiks bo'lsa, masalan "2x  "); `$indent` — keyingi
     * qatorlar boshidagi bo'shliq (taom nomi ostida "osilgan" chekinish).
     *
     * @return array<int, string>
     */
    public function wrap(string $s, int $firstWidth = 0, int $indent = 0): array
    {
        $words = preg_split('/ /', self::clean($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $limit = $firstWidth > 0 ? $firstWidth : $this->width;
        $nextLimit = max(1, $this->width - $indent);
        $lines = [];
        $line = '';

        foreach ($words as $word) {
            if ($line !== '' && mb_strlen($line) + 1 + mb_strlen($word) <= $limit) {
                $line .= ' '.$word;

                continue;
            }

            if ($line !== '') {
                $lines[] = $line;
                $line = '';
                $limit = $nextLimit;
            }

            while (mb_strlen($word) > $limit) {
                $lines[] = mb_substr($word, 0, $limit);
                $word = mb_substr($word, $limit);
                $limit = $nextLimit;
            }

            $line = $word;
        }

        if ($line !== '' || $lines === []) {
            $lines[] = $line;
        }

        foreach ($lines as $i => $l) {
            if ($i > 0) {
                $lines[$i] = str_repeat(' ', $indent).$l;
            }
        }

        return $lines;
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

    /**
     * Xom baytlar (faqat diagnostika: kodlash jadvalini tekshirish). Oddiy matn bu yo'l bilan yozilmaydi.
     */
    public function rawBytes(string $bytes, string $plainNote = ''): self
    {
        $this->buffer .= $bytes."\n";
        $this->plain .= $plainNote."\n";

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

    /**
     * Printer uchun xavfsiz ASCII: kirill -> lotin (o'qiladigan), diakritik harflar asosiy harfga,
     * emoji va qolgan ASCII bo'lmagan belgilar olib tashlanadi. Qator oxiri (\n) saqlanadi.
     */
    public static function ascii(string $s): string
    {
        $s = strtr($s, [
            'ʻ' => "'", 'ʼ' => "'", '‘' => "'", '’' => "'", '`' => "'", '´' => "'",
            '“' => '"', '”' => '"', '«' => '"', '»' => '"',
            '–' => '-', '—' => '-', '−' => '-', '№' => 'N', '×' => 'x', '…' => '...', '•' => '*',
            "\u{00A0}" => ' ', "\t" => ' ', "\r" => '',
        ]);

        $s = self::transliterate($s);

        // Lotin diakritikasi: é -> e (intl bo'lsa).
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($s, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $s = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $s;
            }
        }

        // Qolgan hamma narsa (emoji, ZWJ, variation selector, boshqa yozuvlar) — olib tashlanadi.
        return preg_replace('/[^\x20-\x7E\n]/u', '', $s) ?? preg_replace('/[^\x20-\x7E\n]/', '', $s);
    }

    /**
     * Brauzer cheki uchun: emoji va piktogrammalar olib tashlanadi (termoprinterda dog' bo'lib chiqadi),
     * kirill va lotin harflari (apostrof, diakritika) o'z holicha qoladi. ESC/POS cheki `ascii()` ishlatadi.
     */
    public static function stripPictographs(string $s): string
    {
        $s = preg_replace('/[\x{1F000}-\x{1FFFF}\x{2190}-\x{21FF}\x{2300}-\x{23FF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE00}-\x{FE0F}\x{200D}\x{20E3}\x{E0020}-\x{E007F}]/u', '', $s) ?? $s;

        return trim(preg_replace('/[ \t]{2,}/u', ' ', $s) ?? $s);
    }

    /** `ascii()` + ketma-ket bo'shliqlarni bittaga keltirish va chetlarini kesish (foydalanuvchi matni uchun). */
    public static function clean(string $s): string
    {
        return trim(preg_replace('/ {2,}/', ' ', str_replace("\n", ' ', self::ascii($s))) ?? '');
    }

    /** Kirill harflarni lotinga o'giradi; katta harf so'zda katta harflar orasida bo'lsa to'liq katta ("ЖАРЕНЫЙ" -> "JARENIY"). */
    private static function transliterate(string $s): string
    {
        if (! preg_match('/[\x{0400}-\x{04FF}]/u', $s)) {
            return $s;
        }

        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $isUpper = fn (?string $c): bool => $c !== null && $c !== mb_strtolower($c) && preg_match('/\p{L}/u', $c) === 1;
        $out = '';

        foreach ($chars as $i => $ch) {
            $lower = mb_strtolower($ch);

            if (! array_key_exists($lower, self::CYRILLIC)) {
                $out .= $ch;

                continue;
            }

            $latin = self::CYRILLIC[$lower];

            if ($ch !== $lower && $latin !== '') {
                $inCapsWord = $isUpper($chars[$i + 1] ?? null) || $isUpper($chars[$i - 1] ?? null);
                $latin = ($inCapsWord || strlen($latin) === 1) ? strtoupper($latin) : ucfirst($latin);
            }

            $out .= $latin;
        }

        return $out;
    }
}
