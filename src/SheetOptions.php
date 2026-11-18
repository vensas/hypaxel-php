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

    /**
     * @param int                         $freezeRows    rows pinned at the top (1 pins a header row)
     * @param int                         $freezeColumns columns pinned at the left
     * @param list<float|int|null>        $columnWidths  widths in characters from column A; `null` keeps the default
     * @param array<int<1, 16384>, Column> $columns       column definitions keyed by 1-based column number
     */
    public function __construct(
        public int $freezeRows = 0,
        public int $freezeColumns = 0,
        public array $columnWidths = [],
        public array $columns = [],
        public bool $showGridLines = true,
        public bool $hidden = false,
        Color|string|null $tabColor = null,
    ) {
        $this->tabColor = $tabColor === null ? null : Color::from($tabColor);
    }
}
