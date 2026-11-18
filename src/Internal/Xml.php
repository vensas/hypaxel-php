<?php

declare(strict_types=1);

namespace Vensas\Hypaxel\Internal;

/**
 * @internal
 */
final class Xml
{
    public const DECLARATION = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n";
    public const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    public const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * Matches text that cannot be copied verbatim into a `<t>` element: XML specials, characters
     * XML 1.0 forbids, carriage returns (a parser would turn them into line feeds), literal text
     * that looks like Excel's `_xHHHH_` escape, and leading or trailing whitespace (needs
     * `xml:space`). The `u` flag makes the same single call validate UTF-8:
     * 0 = copy as is, 1 = needs work, false = not valid UTF-8.
     */
    public const NEEDS_CARE = '/[&<>"\x00-\x08\x0B-\x1F\x{FFFE}\x{FFFF}]|_x[0-9A-Fa-f]{4}_|^[ \t\r\n]|[ \t\r\n]$/Du';

    private const NEEDS_ESCAPE = '/[&<>"\x00-\x08\x0B-\x1F\x{FFFE}\x{FFFF}]|_x[0-9A-Fa-f]{4}_/u';
    private const EXCEL_ESCAPES = '/_(?=x[0-9A-Fa-f]{4}_)|[\x00-\x08\x0B-\x1F\x{FFFE}\x{FFFF}]/u';

    /** @var array<int, string> */
    private static array $columnNames = [];

    /**
     * Escapes text for element content or a double-quoted attribute value. Invalid UTF-8 sequences
     * become U+FFFD; characters XML 1.0 cannot carry become Excel's `_xHHHH_` escape.
     */
    public static function escape(string $text): string
    {
        return \preg_match(self::NEEDS_ESCAPE, $text) === 0 ? $text : self::escapeSlow($text);
    }

    public static function escapeSlow(string $text): string
    {
        $text = \htmlspecialchars($text, \ENT_COMPAT | \ENT_SUBSTITUTE | \ENT_XML1, 'UTF-8');

        return \preg_replace_callback(
            self::EXCEL_ESCAPES,
            static fn(array $m): string => match ($m[0]) {
                '_' => '_x005F_',
                "\u{FFFE}" => '_xFFFE_',
                "\u{FFFF}" => '_xFFFF_',
                default => \sprintf('_x%04X_', \ord($m[0])),
            },
            $text,
        ) ?? $text;
    }

    /**
     * A complete `<t>` element for text the fast path rejected.
     */
    public static function textElement(string $text): string
    {
        $escaped = self::escapeSlow($text);
        $first = $escaped[0] ?? '';
        $last = $escaped[-1] ?? '';
        $preserve = $first === ' ' || $first === "\t" || $first === "\n" || $first === "\r"
            || $last === ' ' || $last === "\t" || $last === "\n" || $last === "\r";

        return ($preserve ? '<t xml:space="preserve">' : '<t>') . $escaped . '</t>';
    }

    /**
     * Length in UTF-16 code units, which is what Excel's limits count.
     */
    public static function utf16Length(string $text): int
    {
        $units = 0;
        foreach (\count_chars($text, 1) as $byte => $count) {
            if ($byte < 0x80 || $byte >= 0xC0) {
                $units += $byte >= 0xF0 ? 2 * $count : $count;
            }
        }

        return $units;
    }

    public static function columnName(int $column): string
    {
        return self::$columnNames[$column] ??= self::buildColumnName($column);
    }

    public static function cellRef(int $row, int $column): string
    {
        return self::columnName($column) . $row;
    }

    public static function range(int $firstRow, int $firstColumn, int $lastRow, int $lastColumn): string
    {
        return self::columnName($firstColumn) . $firstRow . ':' . self::columnName($lastColumn) . $lastRow;
    }

    /**
     * Shortest text that reads back as the same double, independent of the `precision` ini settings.
     */
    public static function float(float $value): string
    {
        if ($value > -9.0e15 && $value < 9.0e15 && (float) (int) $value === $value) {
            return (string) (int) $value;
        }
        $text = \json_encode($value);
        if ($text === false || (float) $text !== $value) {
            $text = \sprintf('%.17G', $value);
        }

        return $text;
    }

    private static function buildColumnName(int $column): string
    {
        $name = '';
        while ($column > 0) {
            --$column;
            $name = \chr(65 + $column % 26) . $name;
            $column = \intdiv($column, 26);
        }

        return $name;
    }
}
