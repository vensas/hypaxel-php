<?php

declare(strict_types=1);

namespace Vensas\Hypaxel;

/**
 * Definition of one worksheet column.
 */
final readonly class Column
{
    /**
     * @param float|null   $width in characters of the default font; clamped to 0..255
     * @param StyleId|null $style format of the column's empty cells
     */
    public function __construct(
        public ?float $width = null,
        public ?StyleId $style = null,
        public bool $hidden = false,
    ) {}
}
