<?php

declare(strict_types=1);

namespace Vensas\Hypaxel;

/**
 * Workbook-level options.
 */
final readonly class Options
{
    public const DEFAULT_FLUSH_THRESHOLD = 65536;
    public const MIN_FLUSH_THRESHOLD = 1024;

    public int $compressionLevel;
    public int $flushThreshold;

    /**
     * @param int  $compressionLevel deflate level 0..9; out-of-range values are clamped
     * @param bool $sharedStrings    de-duplicate text into a shared string table: smaller files for
     *                               repetitive text, but memory grows with the number of distinct strings
     * @param int  $flushThreshold   compressed bytes buffered before they are written to the destination (at least 1024)
     * @param bool $cellReferences   write `r="B7"` on every cell instead of only after gaps
     * @param bool $date1904         use the 1904 date system
     */
    public function __construct(
        int $compressionLevel = 1,
        public bool $sharedStrings = false,
        int $flushThreshold = self::DEFAULT_FLUSH_THRESHOLD,
        public bool $cellReferences = false,
        public bool $date1904 = false,
    ) {
        $this->compressionLevel = \max(0, \min(9, $compressionLevel));
        $this->flushThreshold = \max(self::MIN_FLUSH_THRESHOLD, $flushThreshold);
    }
}
