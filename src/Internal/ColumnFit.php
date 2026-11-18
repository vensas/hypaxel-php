<?php

declare(strict_types=1);

namespace Vensas\Hypaxel\Internal;

use Vensas\Hypaxel\AutoFit;

/**
 * Column auto-fit while a worksheet's sample is held back: width estimates for cell content in
 * pixels of Calibri 11 at 96 DPI, the metric Excel's column widths are defined in (a width of `w`
 * characters is `7w + 5` pixels).
 *
 * @internal
 */
final class ColumnFit
{
    /** The sample stops early once the held-back XML reaches this size. */
    public const MAX_SAMPLE_BYTES = 8 * 1024 * 1024;

    public const DEFAULT_WIDTH = 9.140625;

    /** Calibri 11 advance widths in pixels for ASCII 0x20..0x7E. */
    private const ASCII_PX = [
        32 => 3, 3, 4, 7, 7, 10, 10, 2, 4, 4, 7, 7, 3, 4, 3, 5,  // space ! " # $ % & ' ( ) * + , - . /
        7, 7, 7, 7, 7, 7, 7, 7, 7, 7,                           // 0-9
        3, 3, 7, 7, 7, 6, 13,                                   // : ; < = > ? @
        9, 8, 8, 9, 7, 7, 9, 9, 4, 5, 8, 6, 12, 10, 10, 8, 10, 8, 7, 7, 9, 9, 13, 8, 7, 7, // A-Z
        4, 5, 4, 7, 7, 4,                                       // [ \ ] ^ _ `
        7, 8, 6, 8, 8, 5, 7, 8, 4, 4, 7, 4, 12, 8, 8, 8, 8, 5, 6, 5, 8, 7, 11, 7, 7, 6, // a-z
        5, 7, 5, 7,                                             // { | } ~
    ];

    /** East Asian wide and fullwidth characters, plus emoji, which Excel renders about two digits wide. */
    private const WIDE = '/[\x{1100}-\x{115F}\x{2E80}-\x{303E}\x{3041}-\x{33FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}'
        . '\x{A000}-\x{A4CF}\x{AC00}-\x{D7A3}\x{F900}-\x{FAFF}\x{FE30}-\x{FE4F}\x{FF00}-\x{FF60}\x{FFE0}-\x{FFE6}'
        . '\x{1F300}-\x{1F64F}\x{1F900}-\x{1F9FF}\x{20000}-\x{3FFFD}]/u';

    private const DIGIT_PX = 7.0;
    private const WIDE_PX = 15.0;
    private const OTHER_PX = 8.0;

    private const GENERAL = 0;
    private const CONSTANT = 1;
    private const FIXED = 2;

    /** Built-in formats that do not render like General. */
    private const BUILTIN_SHAPES = [
        1 => [0, 1, false, 0],
        2 => [2, 1, false, 0],
        3 => [0, 1, true, 0],
        4 => [2, 1, true, 0],
        9 => [0, 1, false, 1],
        10 => [2, 1, false, 1],
        11 => '1.00E+00',
        14 => '12/31/2026',
        21 => '23:59:59',
        22 => '12/31/2026 23:59',
        46 => '100:00:00',
    ];

    public int $rows = 0;

    /** @var array<int, float> widest content in pixels by 1-based column */
    private array $widths = [];

    /**
     * @var array<int, array{float, int, float, int, int, bool, int}> by style index: scale, shape,
     *                                                                pixels (constant or literal), decimals, minimum integer digits, thousands, percent signs
     */
    private array $styleFits = [];

    /**
     * @param array<int, array{?float, int, bool}> $columns  explicit column definitions: width, style index, hidden
     * @param int                                  $position offset of `<cols>` in the held-back XML
     */
    public function __construct(
        private readonly StyleRegistry $styles,
        private readonly AutoFit $settings,
        private readonly array $columns,
        public readonly int $position,
    ) {}

    /**
     * Counts a completed row; true once the sample is complete.
     */
    public function rowDone(int $xmlLength): bool
    {
        return ++$this->rows >= $this->settings->sampleRows || $xmlLength >= self::MAX_SAMPLE_BYTES;
    }

    public function text(int $style, int $column, string $text): void
    {
        $px = self::textPx($text) * ($this->styleFits[$style] ?? $this->styleFit($style))[0];
        if ($px > ($this->widths[$column] ?? 0.0)) {
            $this->widths[$column] = $px;
        }
    }

    public function number(int $style, int $column, int|float $value): void
    {
        [$scale, $shape, $px, $decimals, $minDigits, $thousands, $percent] = $this->styleFits[$style] ?? $this->styleFit($style);
        $value = (float) $value;
        if (!\is_finite($value)) {
            $px = self::textPx('#NUM!');
        } elseif ($shape === self::GENERAL) {
            $px = self::generalPx($value);
        } elseif ($shape === self::FIXED) {
            $scaled = \abs($value) * 100 ** $percent;
            $rounded = \round($scaled * 10 ** $decimals) / 10 ** $decimals;
            $whole = \floor($rounded);
            $digits = \max($whole >= 1.0 ? (int) \floor(\log10($whole)) + 1 : 0, $minDigits);
            $px += $digits * self::DIGIT_PX + $percent * self::ASCII_PX[37];
            if ($thousands && $digits > 3) {
                $px += \intdiv($digits - 1, 3) * self::ASCII_PX[44];
            }
            if ($decimals > 0) {
                $px += self::ASCII_PX[46] + $decimals * self::DIGIT_PX;
            }
            if ($value < 0.0) {
                $px += self::ASCII_PX[45];
            }
        }
        $px *= $scale;
        if ($px > ($this->widths[$column] ?? 0.0)) {
            $this->widths[$column] = $px;
        }
    }

    /**
     * `<cols>` for the explicit columns and the fitted ones; explicit widths win.
     */
    public function cols(): string
    {
        $columns = $this->columns;
        foreach ($this->widths as $index => $px) {
            if ($px > 0.0 && ($columns[$index][0] ?? null) === null) {
                $columns[$index] = [$this->widthFor($px), $columns[$index][1] ?? 0, $columns[$index][2] ?? false];
            }
        }
        if ($columns === []) {
            return '';
        }
        \ksort($columns);
        $x = '<cols>';
        foreach ($columns as $index => [$width, $style, $hidden]) {
            $x .= self::col($index, $width, $style, $hidden);
        }

        return $x . '</cols>';
    }

    public static function col(int $index, ?float $width, int $style, bool $hidden): string
    {
        return '<col min="' . $index . '" max="' . $index . '" width="'
            . Xml::float($width === null ? self::DEFAULT_WIDTH : \max(0.0, \min(255.0, $width))) . '"'
            . ($width === null ? '' : ' customWidth="1"')
            . ($style === 0 ? '' : ' style="' . $style . '"')
            . ($hidden ? ' hidden="1"' : '') . '/>';
    }

    /**
     * Width of the longest line of `$text`.
     */
    public static function textPx(string $text): float
    {
        if (!\str_contains($text, "\n")) {
            return self::linePx($text);
        }
        $widest = 0.0;
        foreach (\explode("\n", $text) as $line) {
            $widest = \max($widest, self::linePx($line));
        }

        return $widest;
    }

    /**
     * Column width in characters for content `$px` wide, with Excel's cell padding.
     */
    private function widthFor(float $px): float
    {
        $characters = \ceil(($px + 2.0) / 7.0 * 100.0) / 100.0;

        return \max(0.0, \min($characters, $this->settings->maxWidth, 255.0));
    }

    /**
     * Counts bytes instead of splitting into characters: every non-ASCII character has exactly one
     * lead byte (0xC0 and above), and one PCRE pass counts the wide ones.
     */
    private static function linePx(string $line): float
    {
        $px = 0.0;
        $nonAscii = 0;
        foreach (\count_chars($line, 1) as $byte => $count) {
            if ($byte < 0x80) {
                if ($byte !== 0x0D) {
                    $px += $count * (self::ASCII_PX[$byte] ?? self::OTHER_PX);
                }
            } elseif ($byte >= 0xC0) {
                $nonAscii += $count;
            }
        }
        if ($nonAscii > 0) {
            $wide = \preg_match_all(self::WIDE, $line);
            $wide = \is_int($wide) ? $wide : 0;
            $px += $wide * self::WIDE_PX + ($nonAscii - $wide) * self::OTHER_PX;
        }

        return $px;
    }

    private static function generalPx(float $value): float
    {
        $magnitude = \abs($value);
        if ($magnitude !== 0.0 && ($magnitude < 1e-9 || $magnitude >= 1e11)) {
            return self::linePx($value < 0.0 ? '-1.23457E+11' : '1.23457E+11');
        }

        // General shows at most 11 characters, rounding the fraction away.
        return self::linePx(\substr(Xml::float($value), 0, 11));
    }

    /**
     * @return array{float, int, float, int, int, bool, int}
     */
    private function styleFit(int $style): array
    {
        [$size, $bold, $format] = $this->styles->measureInfo($style);
        $scale = \round($size * 100) / 1100;
        if ($bold) {
            $scale *= 1.1;
        }
        $shape = \is_string($format) ? self::customShape($format) : (self::BUILTIN_SHAPES[$format] ?? null);
        if ($shape === null) {
            $fit = [$scale, self::GENERAL, 0.0, 0, 0, false, 0];
        } elseif (\is_string($shape)) {
            $fit = [$scale, self::CONSTANT, self::linePx($shape), 0, 0, false, 0];
        } elseif (\is_float($shape)) {
            $fit = [$scale, self::CONSTANT, $shape, 0, 0, false, 0];
        } else {
            $fit = [$scale, self::FIXED, $shape[4] ?? 0.0, $shape[0], $shape[1], $shape[2], $shape[3]];
        }

        return $this->styleFits[$style] = $fit;
    }

    /**
     * Estimates the rendering of a custom format code from its first section: `null` for General,
     * a float for a fixed width, otherwise decimals, minimum integer digits, thousands separator,
     * percent signs and the width of literal text.
     *
     * @return array{int, int, bool, int, float}|float|null
     */
    private static function customShape(string $code): array|float|null
    {
        $section = \explode(';', $code, 2)[0];
        if ($section === '' || $section === '@' || \strcasecmp($section, 'general') === 0) {
            return null;
        }

        $chars = \preg_split('//u', $section, -1, \PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return null;
        }
        $tokens = '';
        $literal = 0.0;
        $count = \count($chars);
        for ($i = 0; $i < $count; ++$i) {
            $c = $chars[$i];
            if ($c === '"') {
                while (++$i < $count && $chars[$i] !== '"') {
                    $literal += self::linePx($chars[$i]);
                }
            } elseif ($c === '\\' || $c === '_') {
                if (++$i < $count) {
                    $literal += self::linePx($chars[$i]);
                }
            } elseif ($c === '*') {
                ++$i;
            } elseif ($c === '[') {
                $inner = '';
                while (++$i < $count && $chars[$i] !== ']') {
                    $inner .= $chars[$i];
                }
                if (\str_starts_with($inner, '$')) {
                    $literal += self::textPx(\explode('-', \substr($inner, 1), 2)[0]);
                } elseif (\preg_match('/^[hms]*$/Di', $inner) === 1) {
                    $tokens .= 'hhh'; // elapsed time: allow three digits
                }
            } else {
                $tokens .= $c;
            }
        }

        $numeric = \strpbrk($tokens, '0#?') !== false;
        $lower = \strtolower($tokens);
        if (!$numeric) {
            return \strpbrk($lower, 'ymdhs') !== false ? $literal + self::datePx($lower) : null;
        }
        if (\str_contains($lower, 'e+') || \str_contains($lower, 'e-')) {
            return $literal + self::placeholders($tokens) * self::DIGIT_PX + self::linePx('.E+');
        }

        $parts = \explode('.', $tokens, 2);
        $integer = $parts[0];

        return [
            \min(self::placeholders($parts[1] ?? ''), 30),
            \min(\substr_count($integer, '0') + \substr_count($integer, '?'), 30),
            \str_contains($integer, ','),
            \min(\substr_count($tokens, '%'), 2),
            $literal + self::linePx(\str_replace(['0', '#', '?', ',', '.', '%'], '', $tokens)),
        ];
    }

    private static function placeholders(string $tokens): int
    {
        return \substr_count($tokens, '0') + \substr_count($tokens, '#') + \substr_count($tokens, '?');
    }

    /**
     * Width of a date/time format, with each token at its widest rendering.
     */
    private static function datePx(string $tokens): float
    {
        $chars = \preg_split('//u', $tokens, -1, \PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return 0.0;
        }
        $amPm = \str_contains($tokens, 'am/pm');
        $px = 0.0;
        $count = \count($chars);
        for ($i = 0; $i < $count; ++$i) {
            $c = $chars[$i];
            if ($amPm && $c === 'a') {
                $i += 4;
                $px += self::linePx('PM');
                continue;
            }
            $run = 1;
            while ($i + 1 < $count && $chars[$i + 1] === $c) {
                ++$i;
                ++$run;
            }
            $px += match (true) {
                $c === 'y' => $run <= 2 ? 2 * self::DIGIT_PX : 4 * self::DIGIT_PX,
                $c === 'm' && $run === 3 => self::linePx('Sep'),
                $c === 'm' && $run >= 4 => self::linePx('September'),
                $c === 'd' && $run === 3 => self::linePx('Wed'),
                $c === 'd' && $run >= 4 => self::linePx('Wednesday'),
                $c === 'm', $c === 'd', $c === 's' => 2 * self::DIGIT_PX,
                $c === 'h' => \max($run, 2) * self::DIGIT_PX,
                $c === '0' => $run * self::DIGIT_PX,
                default => $run * self::linePx($c),
            };
        }

        return $px;
    }
}
