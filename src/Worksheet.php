<?php

declare(strict_types=1);

namespace Vensas\Hypaxel;

use Vensas\Hypaxel\Exception\DuplicateTableNameException;
use Vensas\Hypaxel\Exception\InvalidArgumentException;
use Vensas\Hypaxel\Exception\LimitExceededException;
use Vensas\Hypaxel\Exception\OverlappingRangeException;
use Vensas\Hypaxel\Exception\UnsupportedValueException;
use Vensas\Hypaxel\Exception\WriterStateException;
use Vensas\Hypaxel\Internal\ColumnFit;
use Vensas\Hypaxel\Internal\Parts;
use Vensas\Hypaxel\Internal\SharedStrings;
use Vensas\Hypaxel\Internal\SheetInfo;
use Vensas\Hypaxel\Internal\StyleRegistry;
use Vensas\Hypaxel\Internal\TableDefinition;
use Vensas\Hypaxel\Internal\Workbook;
use Vensas\Hypaxel\Internal\Xml;
use Vensas\Hypaxel\Internal\ZipStream;

/**
 * The worksheet currently being written. Rows go top to bottom and cells left to right; every row
 * is serialized when it is written and compressed as soon as about 64 KiB of XML have accumulated.
 *
 * Obtain instances from {@see Writer::addWorksheet()}.
 */
final class Worksheet
{
    public const MAX_ROWS = 1_048_576;
    public const MAX_COLUMNS = 16_384;
    public const MAX_CELL_CHARACTERS = 32_767;

    public const MAX_OUTLINE_LEVEL = 7;

    private const XML_BUFFER_SIZE = 65_536;
    private const MAX_ROW_HEIGHT = 409.0;
    private const SECONDS_PER_DAY = 86_400;
    /** Seconds from 1899-12-30 (day 0 of the 1900 system) to the Unix epoch. */
    private const EPOCH_1900 = 2_209_161_600;
    /** Seconds from 1904-01-01 (day 0 of the 1904 system) to the Unix epoch. */
    private const EPOCH_1904 = 2_082_844_800;
    /** 9999-12-31 in the 1900 system. */
    private const MAX_SERIAL = 2_958_465;
    private const DAYS_1900_TO_1904 = 1_462;
    /** Date serials carry ten fractional digits: 1e-10 day is 8640 ns, finer than Excel's 1 ms. */
    private const FRACTION_SCALE = 10_000_000_000;

    private string $buffer;
    private int $row = 0;
    private int $column = 0;
    private bool $rowOpen = false;

    /**
     * The open row was started with {@see beginRow()}: until it has a cell, the next whole-row
     * write fills it instead of starting another row.
     */
    private bool $rowExplicit = false;

    /** Opening tag of the explicitly started row. */
    private string $rowTag = '';
    private bool $completed = false;
    private readonly int $outlineLevels;

    /** Present while the auto-fit sample is held back. */
    private ?ColumnFit $fit = null;

    /** @var list<array{int, int, int, int}> */
    private array $merges = [];

    /** @var list<TableDefinition> */
    private array $tables = [];

    /** @var array<int, array<int, int>> value kind => style index => style index with a date format */
    private array $variants = [[], [], []];

    private readonly ZipStream $zip;
    private readonly StyleRegistry $styles;
    private readonly ?SharedStrings $sharedStrings;

    /**
     * Whether every cell needs a reference. The format places a cell without one right after its
     * predecessor, but PhpSpreadsheet counts cells and rows instead, so references are written for
     * the rest of a row after its first gap and for the rest of a worksheet after its first skipped row.
     */
    private bool $references;
    private bool $rowReferences = false;
    private readonly bool $date1904;
    private readonly bool $exactFloats;

    /**
     * @internal use {@see Writer::addWorksheet()}
     */
    public function __construct(
        private readonly Workbook $workbook,
        private readonly SheetInfo $info,
        SheetOptions $options,
        bool $first,
    ) {
        $this->zip = $workbook->zip;
        $this->styles = $workbook->styles;
        $this->sharedStrings = $workbook->sharedStrings;
        $this->references = $workbook->options->cellReferences;
        $this->date1904 = $workbook->options->date1904;
        // json_encode() yields the shortest round-trip form of a double unless this setting was lowered.
        $this->exactFloats = \in_array(\ini_get('serialize_precision'), ['-1', '17'], true);
        $this->outlineLevels = $options->rowOutlineLevels;
        $this->buffer = $this->header($options, $first);
    }

    /**
     * Current (or last written) 1-based row number; 0 before the first row.
     */
    public function row(): int
    {
        return $this->row;
    }

    /**
     * 1-based column of the last cell written with {@see write()} in the open row; 0 at the start of a row.
     */
    public function column(): int
    {
        return $this->column;
    }

    /**
     * Writes one row.
     *
     * @param iterable<mixed> $values one value per cell; `null` leaves the cell empty
     * @param StyleId|null    $style  format for the cells that do not carry their own ({@see Styled})
     */
    public function writeRow(iterable $values, ?StyleId $style = null): void
    {
        $this->writeMany([$values], $this->styleIndex($style), null, null);
    }

    /**
     * Writes many rows from an array, a generator or any other iterable, e.g. a database cursor.
     *
     * @param iterable<iterable<mixed>> $rows
     * @param StyleId|null              $style format for the cells that do not carry their own
     *
     * @return int number of rows written
     */
    public function writeRows(iterable $rows, ?StyleId $style = null): int
    {
        return $this->writeMany($rows, $this->styleIndex($style), null, null);
    }

    /**
     * Writes one row per record, taking the columns in the given order. No header row is written;
     * {@see Writer::writeWorksheet()} adds one.
     *
     * A record is an array, an `ArrayAccess` or an object whose public properties are read. Missing
     * keys leave the cell empty. A {@see ColumnSpec} with a `value` callable reads anything else,
     * e.g. getters.
     *
     * @param iterable<mixed>               $records
     * @param non-empty-list<string|ColumnSpec> $columns
     *
     * @return int number of rows written
     */
    public function writeRecords(iterable $records, array $columns): int
    {
        if ($columns === []) {
            throw new InvalidArgumentException('At least one column is required to write records.');
        }
        $fields = [];
        $styles = [];
        $styled = false;
        foreach ($columns as $column) {
            if ($column instanceof ColumnSpec) {
                $fields[] = $column->value ?? $column->key;
                $style = $column->numberFormat === null ? 0 : $this->styles->add(new Style($column->numberFormat));
                $styled = $styled || $style !== 0;
                $styles[] = $style;
            } else {
                $fields[] = $column;
                $styles[] = 0;
            }
        }

        return $this->writeMany($records, 0, $fields, $styled ? $styles : null);
    }

    /**
     * Writes a value as the next cell of the open row, starting a row if none is open.
     */
    public function write(mixed $value, ?StyleId $style = null): self
    {
        $this->assertWritable();
        if (!$this->rowOpen) {
            $this->openRow($this->nextRow());
        }
        $column = $this->column + 1;
        if ($column > self::MAX_COLUMNS) {
            throw self::tooManyColumns($this->row);
        }
        $tail = $this->cellTail($value, $this->styleIndex($style), $this->row, $column);
        $this->column = $column;
        if ($tail === '') {
            $this->rowReferences = true;
        } else {
            $this->buffer .= ($this->rowReferences ? '<c r="' . Xml::columnName($column) . $this->row . '"' : '<c') . $tail;
        }

        return $this;
    }

    /**
     * Starts the next row explicitly, e.g. to give it a height, a row format or an outline level.
     * Cells follow with {@see write()}, or the next {@see writeRow()}, {@see writeRows()} or
     * {@see writeRecords()} fills the row as long as it has no cells yet.
     *
     * @param float|null   $height       in points, 0..409
     * @param StyleId|null $style        format of the row's empty cells
     * @param int          $outlineLevel grouping level 0..7, at most {@see SheetOptions::$rowOutlineLevels}
     * @param bool         $collapsed    marks the summary row of a collapsed group (hide the group's rows)
     */
    public function beginRow(
        ?float $height = null,
        ?StyleId $style = null,
        int $outlineLevel = 0,
        bool $hidden = false,
        bool $collapsed = false,
    ): self {
        return $this->beginRowAt($this->row + 1, $height, $style, $outlineLevel, $hidden, $collapsed);
    }

    /**
     * Starts the row with the given 1-based number, which must be greater than any row written so far.
     */
    public function beginRowAt(
        int $row,
        ?float $height = null,
        ?StyleId $style = null,
        int $outlineLevel = 0,
        bool $hidden = false,
        bool $collapsed = false,
    ): self {
        $this->assertWritable();
        if ($row < 1 || $row > self::MAX_ROWS) {
            throw self::rowOutOfRange($row);
        }
        if ($row <= $this->row) {
            throw new InvalidArgumentException(\sprintf('Rows must ascend: row %d follows row %d.', $row, $this->row));
        }
        if ($height !== null && !($height >= 0.0 && $height <= self::MAX_ROW_HEIGHT)) {
            throw new InvalidArgumentException('Row height must be within 0..409 points.');
        }
        if ($outlineLevel < 0 || $outlineLevel > self::MAX_OUTLINE_LEVEL) {
            throw new InvalidArgumentException('Row outline level must be within 0..7.');
        }
        if ($outlineLevel > $this->outlineLevels) {
            throw new InvalidArgumentException(\sprintf(
                'Row outline level %d exceeds the worksheet\'s declared depth of %d; declare the deepest level with new SheetOptions(rowOutlineLevels: ...) when adding the worksheet.',
                $outlineLevel,
                $this->outlineLevels,
            ));
        }
        $styleIndex = $this->styleIndex($style);
        if ($this->rowOpen) {
            $this->closeRow();
        }
        $this->references = $this->references || $row !== $this->row + 1;
        $this->rowTag = '<row r="' . $row . '"'
            . ($height === null ? '' : ' ht="' . Xml::float($height) . '" customHeight="1"')
            . ($styleIndex === 0 ? '' : ' s="' . $styleIndex . '" customFormat="1"')
            . ($hidden ? ' hidden="1"' : '')
            . ($outlineLevel > 0 ? ' outlineLevel="' . $outlineLevel . '"' : '')
            . ($collapsed ? ' collapsed="1"' : '') . '>';
        $this->openRow($row, $this->rowTag);
        $this->rowExplicit = true;

        return $this;
    }

    /**
     * Ends the open row; without an open row it leaves one row empty.
     */
    public function endRow(): self
    {
        $this->assertWritable();
        if ($this->rowOpen) {
            $this->closeRow();
        } elseif ($this->row < self::MAX_ROWS) {
            ++$this->row;
            $this->references = true;
        }

        return $this;
    }

    /**
     * Leaves `$count` cells of the open row empty.
     */
    public function skipCells(int $count = 1): self
    {
        $this->assertWritable();
        if ($count < 0) {
            throw new InvalidArgumentException('Cannot skip a negative number of cells.');
        }
        if (!$this->rowOpen) {
            $this->openRow($this->nextRow());
        }
        if ($this->column + $count > self::MAX_COLUMNS) {
            throw self::tooManyColumns($this->row);
        }
        $this->column += $count;
        $this->rowReferences = $this->rowReferences || $count > 0;

        return $this;
    }

    /**
     * Leaves `$count` rows empty; the next row is written below them.
     */
    public function skipRows(int $count = 1): self
    {
        $this->assertWritable();
        if ($count < 0) {
            throw new InvalidArgumentException('Cannot skip a negative number of rows.');
        }
        if ($this->rowOpen) {
            $this->closeRow();
        }
        $this->row = \min($this->row + $count, self::MAX_ROWS);
        $this->references = $this->references || $count > 0;

        return $this;
    }

    /**
     * Merges a range of cells (1-based, inclusive). Merges are kept in memory until the worksheet completes.
     */
    public function mergeCells(int $firstRow, int $firstColumn, int $lastRow, int $lastColumn): self
    {
        $this->assertWritable();
        self::assertRange($firstRow, $firstColumn, $lastRow, $lastColumn);
        if ($firstRow === $lastRow && $firstColumn === $lastColumn) {
            throw new InvalidArgumentException('A merged range must span more than one cell.');
        }
        $range = [$firstRow, $firstColumn, $lastRow, $lastColumn];
        foreach ($this->tables as $table) {
            if (TableDefinition::overlaps($table->range, $range)) {
                throw new OverlappingRangeException('Merged cells cannot overlap a table.');
            }
        }
        $this->merges[] = $range;

        return $this;
    }

    /**
     * Adds filter drop-downs to a range whose first row is the header (1-based, inclusive).
     */
    public function autoFilter(int $firstRow, int $firstColumn, int $lastRow, int $lastColumn): self
    {
        $this->assertWritable();
        self::assertRange($firstRow, $firstColumn, $lastRow, $lastColumn);
        $range = [$firstRow, $firstColumn, $lastRow, $lastColumn];
        foreach ($this->tables as $table) {
            if (TableDefinition::overlaps($table->range, $range)) {
                throw new OverlappingRangeException('A worksheet auto filter cannot overlap a table (tables have their own filter).');
            }
        }
        $this->info->autoFilter = $range;

        return $this;
    }

    /**
     * Adds an Excel table over a range of this worksheet. The cells can be written before or after;
     * the definition is kept in memory until the worksheet completes.
     *
     * @throws InvalidArgumentException            for an invalid range, style or column names
     * @throws Exception\InvalidTableNameException  for a name that breaks Excel's defined-name rules
     * @throws DuplicateTableNameException          when the workbook already has a table of that name
     * @throws OverlappingRangeException            when the range overlaps a table, merged cells or the auto filter
     */
    public function addTable(Table $table): self
    {
        $this->assertWritable();
        $definition = TableDefinition::create($table, $this->workbook->tableCount + 1);
        $folded = Xml::foldCase($definition->name);
        if (isset($this->workbook->tableNames[$folded])) {
            throw new DuplicateTableNameException(\sprintf('A table named "%s" already exists (table names are unique in the workbook and case-insensitive).', $definition->name));
        }
        foreach ($this->tables as $other) {
            if (TableDefinition::overlaps($other->range, $definition->range)) {
                throw new OverlappingRangeException('Tables cannot overlap each other.');
            }
        }
        foreach ($this->merges as $merge) {
            if (TableDefinition::overlaps($merge, $definition->range)) {
                throw new OverlappingRangeException('A table cannot overlap merged cells.');
            }
        }
        if ($this->info->autoFilter !== null && TableDefinition::overlaps($this->info->autoFilter, $definition->range)) {
            throw new OverlappingRangeException('A table cannot overlap the worksheet auto filter (tables have their own filter).');
        }
        ++$this->workbook->tableCount;
        $this->workbook->tableNames[$folded] = true;
        $this->tables[] = $definition;

        return $this;
    }

    /**
     * @internal called by the writer when the next worksheet starts or the workbook finishes
     */
    public function complete(): void
    {
        if ($this->completed) {
            return;
        }
        if ($this->rowOpen) {
            $this->closeRow();
        }
        if ($this->fit !== null) {
            $this->buffer = $this->endFit($this->buffer);
        }
        $this->completed = true;

        $x = $this->buffer . '</sheetData>';
        $this->buffer = '';
        if ($this->info->autoFilter !== null) {
            $x .= '<autoFilter ref="' . Xml::range(...$this->info->autoFilter) . '"/>';
        }
        if ($this->merges !== []) {
            $x .= '<mergeCells count="' . \count($this->merges) . '">';
            foreach ($this->merges as $range) {
                $x .= '<mergeCell ref="' . Xml::range(...$range) . '"/>';
            }
            $x .= '</mergeCells>';
            $this->merges = [];
        }
        $x .= '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>';
        $tables = $this->tables;
        $this->tables = [];
        if ($tables !== []) {
            $x .= '<tableParts count="' . \count($tables) . '">';
            foreach ($tables as $i => $table) {
                $x .= '<tablePart r:id="rId' . ($i + 1) . '"/>';
            }
            $x .= '</tableParts>';
        }
        $this->zip->write($x . '</worksheet>');
        $this->zip->finishEntry();

        if ($tables !== []) {
            foreach ($tables as $table) {
                $this->zip->startEntry('xl/tables/table' . $table->id . '.xml');
                $this->zip->write($table->toXml());
                $this->zip->finishEntry();
            }
            $sheet = \array_search($this->info, $this->workbook->sheets, true);
            $this->zip->startEntry('xl/worksheets/_rels/sheet' . ((int) $sheet + 1) . '.xml.rels');
            $this->zip->write(Parts::sheetRels($tables));
            $this->zip->finishEntry();
        }
    }

    /**
     * The row loop. Scalars with the default format are serialized inline: in PHP a method call per
     * cell costs about as much as the cell itself.
     *
     * @param iterable<mixed>                        $items        rows, or records when `$fields` is given
     * @param list<string|\Closure(mixed): mixed>|null $fields
     * @param list<int>|null                         $columnStyles style index per column
     */
    private function writeMany(iterable $items, int $style, ?array $fields, ?array $columnStyles): int
    {
        $this->assertWritable();
        // An explicitly started row without cells takes the first row; its tag is restored if no row is written.
        $tag = null;
        $tagRow = 0;
        if ($this->rowOpen) {
            if ($this->rowExplicit && $this->column === 0) {
                $tag = $this->rowTag;
                $tagRow = $this->row;
                $this->buffer = \substr($this->buffer, 0, -\strlen($tag));
                $this->rowOpen = false;
                $this->rowExplicit = false;
                --$this->row;
            } else {
                $this->closeRow();
            }
        }

        $references = $this->references;
        $shared = $this->sharedStrings;
        $exactFloats = $this->exactFloats;
        $plain = $style === 0 && $columnStyles === null && $this->fit === null;
        $names = [];
        $zip = $this->zip;
        $row = $this->row;
        $count = 0;
        $buffer = $this->buffer;
        $this->buffer = '';

        try {
            foreach ($items as $values) {
                if ($fields !== null) {
                    $values = self::recordValues($values, $fields);
                } elseif (!\is_array($values) && !$values instanceof \Traversable) {
                    throw new UnsupportedValueException(\sprintf('A row must be an array or iterable, %s given.', \get_debug_type($values)));
                }
                if ($row >= self::MAX_ROWS) {
                    throw self::rowOutOfRange($row + 1);
                }
                $r = $row + 1;

                if ($plain) {
                    $x = $tag ?? '<row r="' . $r . '">';
                    $column = 0;
                    $gap = $references;
                    foreach ($values as $v) {
                        ++$column;
                        if ($gap) {
                            if (\is_string($v)) {
                                $t = $shared === null && \preg_match(Xml::NEEDS_CARE, $v) === 0 && !isset($v[self::MAX_CELL_CHARACTERS])
                                    ? ' t="inlineStr"><is><t>' . $v . '</t></is></c>'
                                    : $this->textTail($v, '', $r, $column);
                            } elseif (\is_int($v)) {
                                $t = '><v>' . $v . '</v></c>';
                            } elseif (\is_float($v) && $exactFloats && $v > -9.0e15 && $v < 9.0e15) {
                                $i = (int) $v;
                                $t = '><v>' . ((float) $i === $v ? $i : \json_encode($v)) . '</v></c>';
                            } elseif ($v === null) {
                                continue;
                            } elseif (\is_bool($v)) {
                                $t = $v ? ' t="b"><v>1</v></c>' : ' t="b"><v>0</v></c>';
                            } else {
                                $t = $this->cellTail($v, 0, $r, $column);
                                if ($t === '') {
                                    continue;
                                }
                            }
                            $x .= '<c r="' . ($names[$column] ?? ($names[$column] = Xml::columnName($column))) . $r . '"' . $t;
                        } elseif (\is_string($v)) {
                            if ($shared !== null) {
                                if (isset($v[self::MAX_CELL_CHARACTERS])) {
                                    self::assertTextLength($v, $r, $column);
                                }
                                ++$shared->references;
                                $x .= '<c t="s"><v>' . ($shared->index[$v] ?? $shared->add($v)) . '</v></c>';
                            } elseif (\preg_match(Xml::NEEDS_CARE, $v) === 0 && !isset($v[self::MAX_CELL_CHARACTERS])) {
                                $x .= '<c t="inlineStr"><is><t>' . $v . '</t></is></c>';
                            } else {
                                $x .= '<c' . $this->textTail($v, '', $r, $column);
                            }
                        } elseif (\is_int($v)) {
                            $x .= '<c><v>' . $v . '</v></c>';
                        } elseif (\is_float($v)) {
                            if ($v > -9.0e15 && $v < 9.0e15) {
                                $i = (int) $v;
                                $x .= '<c><v>' . ((float) $i === $v ? $i : ($exactFloats ? \json_encode($v) : Xml::float($v))) . '</v></c>';
                            } elseif (\is_finite($v)) {
                                $x .= '<c><v>' . Xml::float($v) . '</v></c>';
                            } else {
                                $x .= '<c t="e"><v>#NUM!</v></c>';
                            }
                        } elseif ($v === null) {
                            $gap = true;
                        } elseif (\is_bool($v)) {
                            $x .= $v ? '<c t="b"><v>1</v></c>' : '<c t="b"><v>0</v></c>';
                        } elseif ($v instanceof \DateTimeInterface) {
                            $x .= '<c' . $this->temporalTail($v, 0, $r, $column);
                        } else {
                            $t = $this->cellTail($v, 0, $r, $column);
                            if ($t === '') {
                                $gap = true;
                            } else {
                                $x .= '<c' . $t;
                            }
                        }
                    }
                    if ($column > self::MAX_COLUMNS) {
                        throw self::tooManyColumns($r);
                    }
                    $buffer .= $x . '</row>';
                } else {
                    $buffer .= ($tag ?? '<row r="' . $r . '">') . $this->styledRow($values, $r, $style, $columnStyles);
                    if ($this->fit !== null && $this->fit->rowDone(\strlen($buffer))) {
                        $buffer = $this->endFit($buffer);
                        $plain = $style === 0 && $columnStyles === null;
                    }
                }

                $tag = null;
                $row = $r;
                ++$count;
                if (isset($buffer[self::XML_BUFFER_SIZE]) && $this->fit === null) {
                    $zip->write($buffer);
                    $buffer = '';
                }
            }
        } finally {
            if ($tag !== null) {
                $buffer .= $tag;
                $row = $tagRow;
                $this->rowOpen = true;
                $this->rowExplicit = true;
            }
            $this->buffer = $buffer;
            $this->row = $row;
        }

        return $count;
    }

    /**
     * @param iterable<mixed> $values
     * @param list<int>|null  $columnStyles
     */
    private function styledRow(iterable $values, int $row, int $style, ?array $columnStyles): string
    {
        $x = '';
        $column = 0;
        $gap = $this->references;
        foreach ($values as $v) {
            $cellStyle = $columnStyles[$column] ?? 0;
            ++$column;
            $t = $this->cellTail($v, $cellStyle === 0 ? $style : $cellStyle, $row, $column);
            if ($t === '') {
                $gap = true;
            } elseif ($gap) {
                $x .= '<c r="' . Xml::columnName($column) . $row . '"' . $t;
            } else {
                $x .= '<c' . $t;
            }
        }
        if ($column > self::MAX_COLUMNS) {
            throw self::tooManyColumns($row);
        }

        return $x . '</row>';
    }

    /**
     * Everything of a `<c>` element after its name and reference, or '' when the cell stays empty.
     */
    private function cellTail(mixed $v, int $style, int $row, int $column): string
    {
        $a = $style === 0 ? '' : ' s="' . $style . '"';
        if (\is_string($v)) {
            return $this->textTail($v, $a, $row, $column, $style);
        }
        if (\is_int($v)) {
            $this->fit?->number($style, $column, $v);

            return $a . '><v>' . $v . '</v></c>';
        }
        if (\is_float($v)) {
            $this->fit?->number($style, $column, $v);

            return \is_finite($v) ? $a . '><v>' . Xml::float($v) . '</v></c>' : $a . ' t="e"><v>#NUM!</v></c>';
        }
        if ($v === null) {
            return $style === 0 ? '' : $a . '/>';
        }
        if (\is_bool($v)) {
            $this->fit?->text($style, $column, $v ? 'TRUE' : 'FALSE');

            return $a . ($v ? ' t="b"><v>1</v></c>' : ' t="b"><v>0</v></c>');
        }
        if ($v instanceof \DateTimeInterface) {
            return $this->temporalTail($v, $style, $row, $column);
        }
        if ($v instanceof Styled) {
            return $this->cellTail($v->value, $this->styleIndex($v->style), $row, $column);
        }
        if ($v instanceof Formula) {
            $this->workbook->usesFormulas = true;

            return $a . '><f>' . Xml::escape($v->expression) . '</f></c>';
        }
        if ($v instanceof \DateInterval) {
            $variant = $this->variant($style, StyleRegistry::KIND_DURATION);
            $days = self::intervalDays($v, $row, $column);
            $this->fit?->number($variant, $column, $days);

            return ($variant === 0 ? '' : ' s="' . $variant . '"') . '><v>' . Xml::float($days) . '</v></c>';
        }
        if ($v instanceof \BackedEnum) {
            return $this->cellTail($v->value, $style, $row, $column);
        }
        if ($v instanceof \UnitEnum) {
            return $this->textTail($v->name, $a, $row, $column, $style);
        }
        if ($v instanceof \Stringable) {
            return $this->textTail((string) $v, $a, $row, $column, $style);
        }

        throw new UnsupportedValueException(\sprintf(
            'Cannot write a value of type %s to the cell at row %d, column %d: use a scalar, null, DateTimeInterface, DateInterval, Stringable, an enum, Formula or Styled.',
            \get_debug_type($v),
            $row,
            $column,
        ));
    }

    private function textTail(string $v, string $a, int $row, int $column, int $style = 0): string
    {
        if (isset($v[self::MAX_CELL_CHARACTERS])) {
            self::assertTextLength($v, $row, $column);
        }
        $this->fit?->text($style, $column, $v);
        $shared = $this->sharedStrings;
        if ($shared !== null) {
            ++$shared->references;

            return $a . ' t="s"><v>' . ($shared->index[$v] ?? $shared->add($v)) . '</v></c>';
        }

        return $a . ' t="inlineStr"><is>'
            . (\preg_match(Xml::NEEDS_CARE, $v) === 0 ? '<t>' . $v . '</t>' : Xml::textElement($v))
            . '</is></c>';
    }

    /**
     * Excel has no time zones: the value is written as the wall-clock time of the object's own zone.
     * Dates outside the range of the date system are written as ISO 8601 text.
     */
    private function temporalTail(\DateTimeInterface $v, int $style, int $row, int $column): string
    {
        $seconds = $v->getTimestamp() + $v->getOffset() + ($this->date1904 ? self::EPOCH_1904 : self::EPOCH_1900);
        // getMicrosecond() exists from PHP 8.4 and is several times cheaper than formatting.
        $micro = \method_exists($v, 'getMicrosecond') ? $v->getMicrosecond() : null;
        if (!\is_int($micro)) {
            $micro = (int) $v->format('u');
        }
        if ($seconds >= 0) {
            $day = \intdiv($seconds, self::SECONDS_PER_DAY);
            $fraction = \intdiv((($seconds % self::SECONDS_PER_DAY) * 1_000_000 + $micro) * 1000 + 4320, 8640);
            if ($fraction >= self::FRACTION_SCALE) {
                ++$day;
                $fraction = 0;
            }
            if ($this->date1904) {
                $valid = $day <= self::MAX_SERIAL - self::DAYS_1900_TO_1904;
            } else {
                // Excel's 1900 system counts a 1900-02-29 that never existed.
                if ($day < 61) {
                    --$day;
                }
                $valid = $day >= 1 && $day <= self::MAX_SERIAL;
            }
            if ($valid) {
                if ($fraction === 0) {
                    $variant = $this->variants[StyleRegistry::KIND_DATE][$style] ?? $this->variant($style, StyleRegistry::KIND_DATE);
                    $this->fit?->number($variant, $column, $day);

                    return ($variant === 0 ? '' : ' s="' . $variant . '"') . '><v>' . $day . '</v></c>';
                }
                $digits = $fraction >= self::FRACTION_SCALE / 10 ? (string) $fraction : \substr((string) (self::FRACTION_SCALE + $fraction), 1);
                if ($fraction % 10 === 0) {
                    $digits = \rtrim($digits, '0');
                }
                $variant = $this->variants[StyleRegistry::KIND_DATETIME][$style] ?? $this->variant($style, StyleRegistry::KIND_DATETIME);
                $this->fit?->number($variant, $column, $day);

                return ($variant === 0 ? '' : ' s="' . $variant . '"') . '><v>' . $day . '.' . $digits . '</v></c>';
            }
        }

        $format = $micro !== 0 ? 'Y-m-d\TH:i:s.u' : ($v->format('His') === '000000' ? 'Y-m-d' : 'Y-m-d\TH:i:s');

        return $this->textTail($v->format($format), $style === 0 ? '' : ' s="' . $style . '"', $row, $column, $style);
    }

    private function variant(int $style, int $kind): int
    {
        return $this->variants[$kind][$style] = $this->styles->variant($style, $kind);
    }

    private static function intervalDays(\DateInterval $interval, int $row, int $column): float
    {
        if ($interval->days === false && ($interval->y !== 0 || $interval->m !== 0)) {
            throw new UnsupportedValueException(\sprintf(
                'The DateInterval at row %d, column %d has years or months and therefore no fixed length; use an interval from DateTime::diff() or write a number of days.',
                $row,
                $column,
            ));
        }
        $days = ($interval->days === false ? $interval->d : $interval->days)
            + $interval->h / 24 + $interval->i / 1440 + ($interval->s + $interval->f) / self::SECONDS_PER_DAY;

        return $interval->invert === 1 ? -$days : $days;
    }

    /**
     * @param list<string|\Closure(mixed): mixed> $fields
     *
     * @return list<mixed>
     */
    private static function recordValues(mixed $record, array $fields): array
    {
        $values = [];
        if (\is_array($record) || $record instanceof \ArrayAccess) {
            foreach ($fields as $field) {
                $values[] = \is_string($field) ? $record[$field] ?? null : $field($record);
            }
        } elseif (\is_object($record)) {
            foreach ($fields as $field) {
                $values[] = \is_string($field) ? $record->{$field} ?? null : $field($record);
            }
        } else {
            throw new UnsupportedValueException(\sprintf('A record must be an array or an object, %s given.', \get_debug_type($record)));
        }

        return $values;
    }

    private function header(SheetOptions $o, bool $first): string
    {
        if ($o->freezeRows < 0 || $o->freezeRows >= self::MAX_ROWS || $o->freezeColumns < 0 || $o->freezeColumns >= self::MAX_COLUMNS) {
            throw new InvalidArgumentException('Frozen panes are out of range.');
        }
        if ($o->rowOutlineLevels < 0 || $o->rowOutlineLevels > self::MAX_OUTLINE_LEVEL) {
            throw new InvalidArgumentException('Row outline levels must be within 0..7.');
        }

        $x = Xml::DECLARATION . '<worksheet xmlns="' . Xml::NS_MAIN . '" xmlns:r="' . Xml::NS_REL . '">';
        if ($o->tabColor !== null || $o->outlineSummaryAbove) {
            $x .= '<sheetPr>' . ($o->tabColor === null ? '' : '<tabColor rgb="' . $o->tabColor->toHex() . '"/>')
                . ($o->outlineSummaryAbove ? '<outlinePr summaryBelow="0"/>' : '') . '</sheetPr>';
        }
        $x .= '<sheetViews><sheetView workbookViewId="0"' . ($first ? ' tabSelected="1"' : '') . ($o->showGridLines ? '' : ' showGridLines="0"');
        if ($o->freezeRows > 0 || $o->freezeColumns > 0) {
            $pane = $o->freezeRows > 0 ? ($o->freezeColumns > 0 ? 'bottomRight' : 'bottomLeft') : 'topRight';
            $x .= '><pane' . ($o->freezeColumns > 0 ? ' xSplit="' . $o->freezeColumns . '"' : '')
                . ($o->freezeRows > 0 ? ' ySplit="' . $o->freezeRows . '"' : '')
                . ' topLeftCell="' . Xml::cellRef($o->freezeRows + 1, $o->freezeColumns + 1) . '" activePane="' . $pane
                . '" state="frozen"/><selection pane="' . $pane . '"/></sheetView></sheetViews>';
        } else {
            $x .= '/></sheetViews>';
        }
        $x .= '<sheetFormatPr defaultRowHeight="15"'
            . ($o->rowOutlineLevels > 0 ? ' outlineLevelRow="' . $o->rowOutlineLevels . '"' : '') . '/>';

        $columns = $this->columns($o);
        if ($o->autoFit !== null) {
            // <cols> is inserted here once the sample has been measured.
            $this->fit = new ColumnFit($this->styles, $o->autoFit, $columns, \strlen($x));
        } elseif ($columns !== []) {
            $x .= '<cols>';
            foreach ($columns as $index => [$width, $style, $hidden]) {
                $x .= ColumnFit::col($index, $width, $style, $hidden);
            }
            $x .= '</cols>';
        }

        return $x . '<sheetData>';
    }

    /**
     * @return array<int, array{?float, int, bool}> width, style index and hidden flag by 1-based column
     */
    private function columns(SheetOptions $o): array
    {
        $columns = [];
        foreach ($o->columns as $index => $column) {
            if (!\is_int($index) || $index < 1 || $index > self::MAX_COLUMNS || !$column instanceof Column) {
                throw new InvalidArgumentException('Columns must be Column instances keyed by a column number within 1..16384.');
            }
            $columns[$index] = [$column->width, $this->styleIndex($column->style), $column->hidden];
        }
        $index = 0;
        foreach ($o->columnWidths as $width) {
            ++$index;
            if ($index > self::MAX_COLUMNS) {
                throw new InvalidArgumentException('Column index must be within 1..16384.');
            }
            if ($width !== null) {
                $columns[$index] = [(float) $width, $columns[$index][1] ?? 0, $columns[$index][2] ?? false];
            }
        }
        \ksort($columns);

        return $columns;
    }

    private function openRow(int $row, ?string $tag = null): void
    {
        $this->row = $row;
        $this->column = 0;
        $this->rowReferences = $this->references;
        $this->rowOpen = true;
        $this->buffer .= $tag ?? '<row r="' . $row . '">';
    }

    private function closeRow(): void
    {
        $this->rowOpen = false;
        $this->rowExplicit = false;
        $this->column = 0;
        $this->buffer .= '</row>';
        if ($this->fit !== null && $this->fit->rowDone(\strlen($this->buffer))) {
            $this->buffer = $this->endFit($this->buffer);
        }
        if (isset($this->buffer[self::XML_BUFFER_SIZE]) && $this->fit === null) {
            $this->zip->write($this->buffer);
            $this->buffer = '';
        }
    }

    /**
     * Inserts the fitted `<cols>` into the held-back XML; streaming resumes from here.
     */
    private function endFit(string $buffer): string
    {
        \assert($this->fit !== null);
        $position = $this->fit->position;
        $cols = $this->fit->cols();
        $this->fit = null;

        return \substr($buffer, 0, $position) . $cols . \substr($buffer, $position);
    }

    private function nextRow(): int
    {
        if ($this->row >= self::MAX_ROWS) {
            throw self::rowOutOfRange($this->row + 1);
        }

        return $this->row + 1;
    }

    private function styleIndex(?StyleId $style): int
    {
        if ($style === null) {
            return 0;
        }
        if ($style->index < 0 || $style->index >= $this->styles->count()) {
            throw new InvalidArgumentException(\sprintf('Style id %d does not belong to this writer.', $style->index));
        }

        return $style->index;
    }

    private function assertWritable(): void
    {
        if ($this->completed) {
            throw new WriterStateException(\sprintf('Worksheet "%s" is complete: a worksheet can only be written until the next one is added or the workbook is finished.', $this->info->name));
        }
    }

    private static function assertTextLength(string $text, int $row, int $column): void
    {
        $length = Xml::utf16Length($text);
        if ($length > self::MAX_CELL_CHARACTERS) {
            throw new LimitExceededException(\sprintf('The cell at row %d, column %d has %d characters; the limit is 32767.', $row, $column, $length));
        }
    }

    private static function assertRange(int $firstRow, int $firstColumn, int $lastRow, int $lastColumn): void
    {
        if ($firstRow < 1 || $lastRow < $firstRow || $lastRow > self::MAX_ROWS
            || $firstColumn < 1 || $lastColumn < $firstColumn || $lastColumn > self::MAX_COLUMNS) {
            throw new InvalidArgumentException('Invalid cell range.');
        }
    }

    private static function rowOutOfRange(int $row): LimitExceededException
    {
        return new LimitExceededException(\sprintf('Row %d is outside 1..1048576.', $row));
    }

    private static function tooManyColumns(int $row): LimitExceededException
    {
        return new LimitExceededException(\sprintf('Row %d exceeds 16384 columns.', $row));
    }
}
