<?php

declare(strict_types=1);

namespace Vensas\Hypaxel;

/**
 * A formula cell such as `new Formula('SUM(A1:A9)')`; a leading `=` is optional.
 * Excel computes the result when the file is opened.
 */
final readonly class Formula
{
    public string $expression;

    public function __construct(string $expression)
    {
        $this->expression = \str_starts_with($expression, '=') ? \substr($expression, 1) : $expression;
    }
}
