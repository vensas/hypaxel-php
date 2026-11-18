<?php

declare(strict_types=1);

namespace Vensas\Hypaxel\Internal;

/**
 * @internal
 */
final class SheetInfo
{
    /** @var array{int, int, int, int}|null first row, first column, last row, last column */
    public ?array $autoFilter = null;

    public function __construct(
        public readonly string $name,
        public readonly bool $hidden,
    ) {}
}
