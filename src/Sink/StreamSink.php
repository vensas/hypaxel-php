<?php

declare(strict_types=1);

namespace Vensas\Hypaxel\Sink;

use Vensas\Hypaxel\Exception\InvalidArgumentException;
use Vensas\Hypaxel\Exception\IoException;

final class StreamSink implements Sink
{
    /** @var resource */
    private $stream;

    /**
     * @param resource $stream       any writable stream; it does not have to be seekable
     * @param bool     $closeOnFinish close the stream when the workbook is complete
     */
    public function __construct(mixed $stream, private readonly bool $closeOnFinish = false)
    {
        if (!\is_resource($stream) || \get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('Expected an open stream resource.');
        }
        $this->stream = $stream;
    }

    public function write(string $bytes): void
    {
        $length = \strlen($bytes);
        $written = @\fwrite($this->stream, $bytes);
        while ($written !== $length) {
            // A zero-length write on a blocking stream means the peer is gone (closed socket, full disk).
            if ($written === false || $written === 0) {
                throw new IoException('Writing to the destination stream failed.');
            }
            $bytes = \substr($bytes, $written);
            $length -= $written;
            $written = @\fwrite($this->stream, $bytes);
        }
    }

    public function close(): void
    {
        if ($this->closeOnFinish) {
            if (!@\fclose($this->stream)) {
                throw new IoException('Closing the destination stream failed.');
            }

            return;
        }
        if (!@\fflush($this->stream)) {
            throw new IoException('Flushing the destination stream failed.');
        }
    }
}
