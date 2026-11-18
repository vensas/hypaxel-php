<?php

declare(strict_types=1);

namespace Vensas\Hypaxel\Sink;

use Vensas\Hypaxel\Exception\IoException;

/**
 * Destination of the finished bytes. Chunks arrive strictly in order and are never rewritten,
 * so a sink does not have to be seekable.
 */
interface Sink
{
    /**
     * @throws IoException
     */
    public function write(string $bytes): void;

    /**
     * Called once when the workbook is complete.
     *
     * @throws IoException
     */
    public function close(): void;
}
