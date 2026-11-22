<?php

declare(strict_types=1);

namespace Vensas\Hypaxel\Internal;

use Vensas\Hypaxel\Exception\InvalidArgumentException;
use Vensas\Hypaxel\Exception\InvalidTableNameException;
use Vensas\Hypaxel\Table;
use Vensas\Hypaxel\Worksheet;

/**
 * A validated table, kept until its worksheet completes.
 *
 * @internal
 */
final readonly class TableDefinition
{
    private const MAX_NAME_LENGTH = 255;

    public string $name;

    /** @var array{int, int, int, int} first row, first column, last row, last column */
    public array $range;

    /** @var list<string> */
    private array $columns;

    /**
     * @param list<string> $columns
     */
    private function __construct(public int $id, private Table $table, string $name, array $columns)
    {
        $this->name = $name;
        $this->columns = $columns;
        $this->range = [$table->firstRow, $table->firstColumn, $table->lastRow, $table->lastColumn];
    }

    /**
     * Validates everything that does not depend on the other tables, merges and filters.
     */
    public static function create(Table $table, int $id): self
    {
        $t = $table;
        if ($t->firstRow < 1 || $t->lastRow < $t->firstRow || $t->lastRow > Worksheet::MAX_ROWS
            || $t->firstColumn < 1 || $t->lastColumn < $t->firstColumn || $t->lastColumn > Worksheet::MAX_COLUMNS) {
            throw new InvalidArgumentException('Invalid cell range.');
        }
        if ($t->headerRow && $t->lastRow === $t->firstRow) {
            throw new InvalidArgumentException('A table with a header row needs at least one data row.');
        }

        $name = $t->name ?? 'Table' . $id;
        if ($t->name !== null && !self::isValidName($t->name)) {
            throw new InvalidTableNameException(\sprintf(
                'Invalid table name "%s": names start with a letter, "_" or "\\", contain only letters, digits, "_", "." and "\\", have at most 255 characters and cannot look like a cell reference.',
                $t->name,
            ));
        }

        $width = $t->lastColumn - $t->firstColumn + 1;
        $columns = \array_values($t->columns);
        if ($columns === []) {
            if ($t->headerRow) {
                throw new InvalidArgumentException('A table with a header row needs its column names (Table $columns).');
            }
            for ($i = 1; $i <= $width; ++$i) {
                $columns[] = 'Column' . $i;
            }
        }
        if (\count($columns) !== $width) {
            throw new InvalidArgumentException(\sprintf('The number of table column names (%d) must equal the width of the range (%d).', \count($columns), $width));
        }
        $seen = [];
        $names = [];
        foreach ($columns as $column) {
            if (!\is_string($column) || $column === '' || \preg_match('//u', $column) !== 1 || Xml::utf16Length($column) > 255) {
                throw new InvalidArgumentException('Table column names must be strings of 1 to 255 characters.');
            }
            $folded = Xml::foldCase($column);
            if (isset($seen[$folded])) {
                throw new InvalidArgumentException(\sprintf('Table column names must be unique (case-insensitive); "%s" is repeated.', $column));
            }
            $seen[$folded] = true;
            $names[] = $column;
        }

        return new self($id, $table, $name, $names);
    }

    /**
     * True when two inclusive ranges share at least one cell.
     *
     * @param array{int, int, int, int} $a
     * @param array{int, int, int, int} $b
     */
    public static function overlaps(array $a, array $b): bool
    {
        return $a[0] <= $b[2] && $b[0] <= $a[2] && $a[1] <= $b[3] && $b[1] <= $a[3];
    }

    /**
     * Excel's defined-name rules: a letter, `_` or `\` first, then letters, digits, `_`, `.` and `\`,
     * at most 255 characters, and nothing that reads as an A1 or R1C1 reference.
     */
    public static function isValidName(string $name): bool
    {
        return \preg_match('/^[\p{L}\p{Nl}_\\\\][\p{L}\p{M}\p{N}_.\\\\]*$/Du', $name) === 1
            && Xml::utf16Length($name) <= self::MAX_NAME_LENGTH
            && !self::looksLikeReference($name);
    }

    public function toXml(): string
    {
        $t = $this->table;
        $ref = Xml::range(...$this->range);
        $x = Xml::DECLARATION . '<table xmlns="' . Xml::NS_MAIN . '" id="' . $this->id . '" name="' . Xml::escape($this->name)
            . '" displayName="' . Xml::escape($this->name) . '" ref="' . $ref . '"'
            . ($t->headerRow ? '' : ' headerRowCount="0"') . ' totalsRowShown="0">'
            . ($t->autoFilter && $t->headerRow ? '<autoFilter ref="' . $ref . '"/>' : '')
            . '<tableColumns count="' . \count($this->columns) . '">';
        foreach ($this->columns as $i => $column) {
            // Attribute normalization would turn line breaks and tabs into spaces; Excel escapes them.
            $x .= '<tableColumn id="' . ($i + 1) . '" name="'
                . \str_replace(["\n", "\r", "\t"], ['_x000A_', '_x000D_', '_x0009_'], Xml::escape($column)) . '"/>';
        }

        return $x . '</tableColumns><tableStyleInfo' . ($t->style->name === null ? '' : ' name="' . \htmlspecialchars($t->style->name, \ENT_COMPAT | \ENT_XML1, 'UTF-8') . '"')
            . ' showFirstColumn="' . (int) $t->highlightFirstColumn . '" showLastColumn="' . (int) $t->highlightLastColumn
            . '" showRowStripes="' . (int) $t->rowStripes . '" showColumnStripes="' . (int) $t->columnStripes . '"/></table>';
    }

    private static function looksLikeReference(string $name): bool
    {
        $upper = \strtoupper($name);
        if (\preg_match('/^([A-Z]{1,3})([0-9]+)$/D', $upper, $m) === 1) {
            $column = 0;
            foreach (\str_split($m[1]) as $letter) {
                $column = $column * 26 + \ord($letter) - 64;
            }
            $row = \ltrim($m[2], '0');
            if ($column <= Worksheet::MAX_COLUMNS && $row !== '' && \strlen($row) <= 7 && (int) $row <= Worksheet::MAX_ROWS) {
                return true;
            }
        }

        return \preg_match('/^(?:R[0-9]*C?[0-9]*|C[0-9]*)$/D', $upper) === 1;
    }
}
