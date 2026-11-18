<?php

declare(strict_types=1);

namespace Vensas\Hypaxel;

/**
 * A cell value with its own style, e.g. `new Styled(1234.5, $money)`. A `null` value writes an
 * empty cell that carries the format.
 */
final readonly class Styled
{
    public function __construct(public mixed $value, public StyleId $style) {}
}
