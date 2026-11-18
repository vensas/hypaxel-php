<?php

declare(strict_types=1);

namespace Vensas\Hypaxel;

/**
 * An Excel table (a named, styled range with a header row and filter buttons), added with
 * {@see Worksheet::addTable()}.
 *
 * With a header row (the default), `$columns` must list the header texts exactly as written in the
 * range's first row: Excel identifies table columns by those names and repairs the file otherwise.
 *
 *     $sheet->writeRow(['Product', 'Units']);
 *     $sheet->writeRows([['Widget', 3], ['Gadget', 5]]);
 *     $sheet->addTable(new Table(1, 1, $sheet->row(), 2, name: 'Orders', columns: ['Product', 'Units']));
 */
final readonly class Table
{
    public TableStyle $style;

    /**
     * @param int          $firstRow             1-based and inclusive, like the other range corners
     * @param string|null  $name                 workbook-unique name used in structured references; `null` gives `Table1`, `Table2`, ...
     * @param list<string> $columns              column names; required with a header row, otherwise `Column1`, `Column2`, ... by default
     * @param bool         $headerRow            the first row of the range holds the column names
     * @param bool         $autoFilter           filter buttons in the header row
     * @param bool         $rowStripes           banded rows
     * @param bool         $columnStripes        banded columns
     * @param bool         $highlightFirstColumn emphasize the first column
     * @param bool         $highlightLastColumn  emphasize the last column
     */
    public function __construct(
        public int $firstRow,
        public int $firstColumn,
        public int $lastRow,
        public int $lastColumn,
        public ?string $name = null,
        public array $columns = [],
        ?TableStyle $style = null,
        public bool $headerRow = true,
        public bool $autoFilter = true,
        public bool $rowStripes = true,
        public bool $columnStripes = false,
        public bool $highlightFirstColumn = false,
        public bool $highlightLastColumn = false,
    ) {
        $this->style = $style ?? TableStyle::default();
    }
}
