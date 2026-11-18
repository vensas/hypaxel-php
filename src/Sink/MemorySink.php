<?php

declare(strict_types=1);

namespace Vensas\Hypaxel\Sink;

/**
 * Collects the output in memory until it is taken.
 */
final class MemorySink implements Sink
{
    private string $buffer = '';

    public function write(string $bytes): void
    {
        $this->buffer .= $bytes;
    }

    public function close(): void {}

    public function isEmpty(): bool
    {
        return $this->buffer === '';
    }

    /**
     * Returns the bytes collected since the last call and forgets them.
     */
    public function take(): string
    {
        $bytes = $this->buffer;
        $this->buffer = '';

        return $bytes;
    }
}
