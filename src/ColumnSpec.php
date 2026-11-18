<?php

declare(strict_types=1);

namespace Vensas\Hypaxel;

/**
 * One column of a record export: which value to take and how to present it.
 */
final readonly class ColumnSpec
{
    /** @var (\Closure(mixed): mixed)|null */
    public ?\Closure $value;

    /**
     * @param string                        $key          array key or property name read from each record
     * @param string|null                   $header       header text; defaults to the key
     * @param float|null                    $width        column width in characters; defaults to an estimate from the header
     * @param string|null                   $numberFormat Excel format code for the column's cells
     * @param (callable(mixed): mixed)|null $value        computes the cell from the record instead of reading `$key`
     */
    public function __construct(
        public string $key,
        public ?string $header = null,
        public ?float $width = null,
        public ?string $numberFormat = null,
        ?callable $value = null,
    ) {
        $this->value = $value === null ? null : $value(...);
    }
}
