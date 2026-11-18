<?php

declare(strict_types=1);

namespace Vensas\Hypaxel;

use Vensas\Hypaxel\Exception\InvalidArgumentException;

/**
 * Column auto-fit settings for {@see SheetOptions::$autoFit}.
 *
 * Column widths precede the cell data in the file, so the worksheet's XML is held back until
 * `$sampleRows` rows have been written (or the worksheet ends), the widths are estimated from those
 * rows, and streaming continues as usual. Memory is bounded by the sample: at most `$sampleRows`
 * rows or 8 MiB of XML, whichever comes first. Widths are estimated from the text, number format
 * and font size of each cell; formulas and merged cells are not measured.
 */
final readonly class AutoFit
{
    public const DEFAULT_SAMPLE_ROWS = 1000;
    public const DEFAULT_MAX_WIDTH = 255.0;

    /**
     * @param int   $sampleRows rows measured before the widths are fixed (at least 1)
     * @param float $maxWidth   upper bound for a fitted width, in characters
     */
    public function __construct(
        public int $sampleRows = self::DEFAULT_SAMPLE_ROWS,
        public float $maxWidth = self::DEFAULT_MAX_WIDTH,
    ) {
        if ($sampleRows < 1) {
            throw new InvalidArgumentException('The auto-fit sample needs at least one row.');
        }
        if (!($maxWidth > 0.0)) {
            throw new InvalidArgumentException('The auto-fit maximum width must be positive.');
        }
    }
}
