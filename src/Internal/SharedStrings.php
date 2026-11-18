<?php

declare(strict_types=1);

namespace Vensas\Hypaxel\Internal;

/**
 * @internal
 */
final class SharedStrings
{
    /**
     * Distinct strings mapped to their index. PHP turns keys such as "42" into integers; casting
     * the key back to a string restores the original text exactly.
     *
     * @var array<array-key, int>
     */
    public array $index = [];

    public int $references = 0;

    public function add(string $text): int
    {
        return $this->index[$text] = \count($this->index);
    }
}
