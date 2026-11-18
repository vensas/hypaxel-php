<?php

declare(strict_types=1);

namespace Vensas\Hypaxel\Sink;

/**
 * Writes to the response body (`echo`) and asks the SAPI to send each chunk right away.
 */
final class OutputSink implements Sink
{
    public function write(string $bytes): void
    {
        echo $bytes;
        \flush();
    }

    public function close(): void {}
}
