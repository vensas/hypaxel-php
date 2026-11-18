<?php

declare(strict_types=1);

namespace Vensas\Hypaxel;

/**
 * Worksheet settings. They precede the cell data in the file, so they are fixed when the
 * worksheet is added.
 */
final readonly class SheetOptions
{
    public ?Color $tabColor;
    public ?AutoFit $autoFit;

    /**
     * @param int                          $freezeRows          rows pinned at the top (1 pins a header row)
     * @param int                          $freezeColumns       columns pinned at the left
     * @param list<float|int|null>         $columnWidths        widths in characters from column A; `null` keeps the default
     * @param array<int<1, 16384>, Column> $columns             column definitions keyed by 1-based column number
     * @param int                          $rowOutlineLevels    deepest row outline level (0..7) that {@see Worksheet::beginRow()} will use;
     *                                                          Excel sizes the outline bar from it, so it is declared up front
     * @param bool                         $outlineSummaryAbove put the expand/collapse button of a row group on the summary row above it
     * @param AutoFit|bool                 $autoFit             size columns without an explicit width to their content; `true` uses the defaults
     */
    public function __construct(
        public int $freezeRows = 0,
        public int $freezeColumns = 0,
        public array $columnWidths = [],
        public array $columns = [],
        public bool $showGridLines = true,
        public bool $hidden = false,
        Color|string|null $tabColor = null,
        public int $rowOutlineLevels = 0,
        public bool $outlineSummaryAbove = false,
        AutoFit|bool $autoFit = false,
    ) {
        $this->tabColor = $tabColor === null ? null : Color::from($tabColor);
        $this->autoFit = $autoFit === true ? new AutoFit() : ($autoFit === false ? null : $autoFit);
    }
}
