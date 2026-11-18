<?php

declare(strict_types=1);

namespace Vensas\Hypaxel\Tests\Support;

/**
 * A non-seekable stream wrapper that accepts only a few bytes per write, like a slow socket.
 */
final class ChunkedStream
{
    public const SCHEME = 'hypaxel-chunked';

    public static string $written = '';
    public static int $maxChunk = 7;
    public static int $failAfter = \PHP_INT_MAX;

    /** @var resource|null */
    public $context;

    public static function register(): void
    {
        if (!\in_array(self::SCHEME, \stream_get_wrappers(), true)) {
            \stream_wrapper_register(self::SCHEME, self::class);
        }
        self::$written = '';
        self::$maxChunk = 7;
        self::$failAfter = \PHP_INT_MAX;
    }

    public function stream_open(): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        if (\strlen(self::$written) >= self::$failAfter) {
            return 0;
        }
        $chunk = \substr($data, 0, self::$maxChunk);
        self::$written .= $chunk;

        return \strlen($chunk);
    }

    public function stream_seek(): bool
    {
        return false;
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void {}
}
