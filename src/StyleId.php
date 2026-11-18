<?php

declare(strict_types=1);

namespace Vensas\Hypaxel;

/**
 * Handle to a format registered with {@see Writer::addStyle()}. Only valid for that writer.
 */
final readonly class StyleId
{
    /**
     * @internal obtain handles from {@see Writer::addStyle()}
     */
    public function __construct(public int $index) {}
}
