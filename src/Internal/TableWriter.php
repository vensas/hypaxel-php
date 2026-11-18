<?php

declare(strict_types=1);

namespace Vensas\Hypaxel\Internal;

use Vensas\Hypaxel\Border;
use Vensas\Hypaxel\BorderLine;
use Vensas\Hypaxel\BorderStyle;
use Vensas\Hypaxel\ColumnSpec;
use Vensas\Hypaxel\Exception\UnsupportedValueException;
use Vensas\Hypaxel\SheetOptions;
use Vensas\Hypaxel\Style;
use Vensas\Hypaxel\Worksheet;
use Vensas\Hypaxel\Writer;

/**
 * Writes one worksheet from rows or records: the first item decides which, then a styled, frozen
 * header row is written when the columns are known.
 *
 * @internal
 */
final class TableWriter
{
    private const HEADER_FILL = '#D9D9D9';
    private const MIN_WIDTH = 12;
    private const MAX_WIDTH = 60;

    private ?Worksheet $sheet = null;
    private bool $records = false;
    private int $headerCount = 0;

    /** @var non-empty-list<string|ColumnSpec>|null */
    private ?array $recordColumns = null;

    /**
     * @param list<string|ColumnSpec>|null $columns
     */
    public function __construct(
        private readonly Writer $writer,
        private readonly string $sheetName,
        private readonly ?array $columns,
        private readonly bool $header,
        private readonly bool $autoFilter,
    ) {}

    /**
     * @param iterable<mixed> $data
     */
    public function writeAll(iterable $data): int
    {
        if (\is_array($data)) {
            if ($data === []) {
                return 0;
            }
            $this->start($data[\array_key_first($data)]);

            return $this->writeBatch($data);
        }

        $count = 0;
        foreach ($data as $item) {
            $count += $this->write($item);
        }

        return $count;
    }

    public function write(mixed $item): int
    {
        if ($this->sheet === null) {
            $this->start($item);
        }

        return $this->writeBatch([$item]);
    }

    public function finish(): void
    {
        $sheet = $this->sheet ?? $this->start(null);
        if ($this->headerCount > 0 && $this->autoFilter) {
            $sheet->autoFilter(1, 1, \max($sheet->row(), 1), $this->headerCount);
        }
    }

    /**
     * @param iterable<mixed> $items
     */
    private function writeBatch(iterable $items): int
    {
        \assert($this->sheet !== null);
        if ($this->records) {
            \assert($this->recordColumns !== null);

            return $this->sheet->writeRecords($items, $this->recordColumns);
        }

        /** @var iterable<iterable<mixed>> $items */
        return $this->sheet->writeRows($items);
    }

    private function start(mixed $first): Worksheet
    {
        $columns = $this->columns;
        $this->records = $first !== null && !self::isRow($first);
        if ($this->records && ($columns === null || $columns === [])) {
            $columns = self::keysOf($first);
        }
        if ($this->records) {
            if ($columns === null || $columns === []) {
                throw new UnsupportedValueException('The first record has no keys or public properties; pass the columns explicitly.');
            }
            $this->recordColumns = $columns;
        }

        $headers = [];
        $widths = [];
        foreach ($columns ?? [] as $column) {
            $text = $column instanceof ColumnSpec ? $column->header ?? $column->key : $column;
            $headers[] = $text;
            $widths[] = $column instanceof ColumnSpec && $column->width !== null
                ? $column->width
                : (float) \min(\max(Xml::utf16Length($text) + 3, self::MIN_WIDTH), self::MAX_WIDTH);
        }
        $withHeader = $this->header && $headers !== [];
        $this->headerCount = $withHeader ? \count($headers) : 0;

        $sheet = $this->writer->addWorksheet($this->sheetName, new SheetOptions(
            freezeRows: $withHeader ? 1 : 0,
            columnWidths: $widths,
        ));
        if ($withHeader) {
            $style = (new Style(fill: self::HEADER_FILL, border: new Border(bottom: new BorderLine(BorderStyle::Thin))))->bold();
            $sheet->writeRow($headers, $this->writer->addStyle($style));
        }

        return $this->sheet = $sheet;
    }

    private static function isRow(mixed $item): bool
    {
        return \is_array($item) ? \array_is_list($item) : $item instanceof \Traversable && !$item instanceof \ArrayAccess;
    }

    /**
     * @return list<string>
     */
    private static function keysOf(mixed $record): array
    {
        if (\is_object($record) && !$record instanceof \ArrayAccess) {
            $record = \get_object_vars($record);
        }
        if (!\is_array($record)) {
            return [];
        }

        return \array_map(static fn(int|string $key): string => (string) $key, \array_keys($record));
    }
}
