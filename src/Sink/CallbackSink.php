<?php

declare(strict_types=1);

namespace Vensas\Hypaxel\Sink;

/**
 * Hands every chunk to a callable, e.g. `$psr7Stream->write(...)`.
 */
final class CallbackSink implements Sink
{
    /** @var \Closure(string): mixed */
    private readonly \Closure $write;

    /** @var (\Closure(): mixed)|null */
    private readonly ?\Closure $close;

    /**
     * @param callable(string): mixed  $write
     * @param (callable(): mixed)|null $close
     */
    public function __construct(callable $write, ?callable $close = null)
    {
        $this->write = $write(...);
        $this->close = $close === null ? null : $close(...);
    }

    public function write(string $bytes): void
    {
        ($this->write)($bytes);
    }

    public function close(): void
    {
        if ($this->close !== null) {
            ($this->close)();
        }
    }
}
