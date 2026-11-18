<?php

declare(strict_types=1);

namespace Vensas\Hypaxel\Internal;

use Vensas\Hypaxel\Exception\IoException;
use Vensas\Hypaxel\Exception\WriterStateException;
use Vensas\Hypaxel\Sink\Sink;

/**
 * Minimal streaming ZIP writer.
 *
 * Every entry carries a data descriptor (general purpose flag bit 3), so sizes and CRCs never have
 * to be patched into bytes that were already sent: the archive is produced strictly front to back.
 * ZIP64 records are added when an entry, an offset or the entry count exceeds the classic limits.
 *
 * Entries are deflated with zlib's gzip framing and the frame is stripped again: zlib then computes
 * the CRC-32 while it compresses, which is about twice as fast as hashing the data a second time in
 * PHP. The raw deflate stream between gzip header and trailer is exactly what a ZIP entry holds.
 *
 * @internal
 */
final class ZipStream
{
    private const GZIP_HEADER_LENGTH = 10;
    private const GZIP_TRAILER_LENGTH = 8;

    private const VERSION_DEFAULT = 20;
    private const VERSION_ZIP64 = 45;
    private const FLAG_DATA_DESCRIPTOR = 0x0008;
    private const METHOD_DEFLATE = 8;
    // 1980-01-01 00:00:00: a fixed timestamp keeps the output reproducible.
    private const DOS_TIME = 0;
    private const DOS_DATE = 0x0021;

    private string $output = '';
    private int $position = 0;
    private ?\DeflateContext $deflate = null;
    private int $headerBytesToStrip = 0;
    private string $entryName = '';
    private int $entryOffset = 0;
    private int $uncompressed = 0;
    private int $compressed = 0;
    private bool $finished = false;

    /** @var list<array{name: string, crc: int, compressed: int, uncompressed: int, offset: int}> */
    private array $central = [];

    /**
     * @param int $zip64SizeLimit  largest size or offset the classic 32-bit fields can hold
     * @param int $zip64CountLimit largest entry count the classic end record can hold
     */
    public function __construct(
        private readonly Sink $sink,
        private readonly int $level,
        private readonly int $flushThreshold,
        private readonly int $zip64SizeLimit = 0xFFFFFFFF,
        private readonly int $zip64CountLimit = 0xFFFF,
    ) {}

    public function position(): int
    {
        return $this->position;
    }

    public function startEntry(string $name): void
    {
        if ($this->deflate !== null || $this->finished) {
            throw new WriterStateException('The previous ZIP entry is not finished.');
        }
        $deflate = \deflate_init(\ZLIB_ENCODING_GZIP, ['level' => $this->level]);
        if ($deflate === false) {
            throw new IoException('Could not initialise the deflate compressor.');
        }
        $this->deflate = $deflate;
        $this->headerBytesToStrip = self::GZIP_HEADER_LENGTH;
        $this->entryName = $name;
        $this->entryOffset = $this->position;
        $this->uncompressed = 0;
        $this->compressed = 0;

        $this->emit(
            \pack('VvvvvvVVVvv', 0x04034B50, self::VERSION_DEFAULT, self::FLAG_DATA_DESCRIPTOR, self::METHOD_DEFLATE, self::DOS_TIME, self::DOS_DATE, 0, 0, 0, \strlen($name), 0)
            . $name,
        );
    }

    public function write(string $data): void
    {
        if ($this->deflate === null) {
            throw new WriterStateException('No ZIP entry is open.');
        }
        $this->uncompressed += \strlen($data);
        $chunk = \deflate_add($this->deflate, $data, \ZLIB_NO_FLUSH);
        if ($chunk === false) {
            throw new IoException('Deflate failed.');
        }
        if ($chunk === '') {
            return;
        }
        if ($this->headerBytesToStrip > 0) {
            $chunk = $this->stripHeader($chunk);
        }
        $this->compressed += \strlen($chunk);
        $this->emit($chunk);
    }

    public function finishEntry(): void
    {
        if ($this->deflate === null) {
            throw new WriterStateException('No ZIP entry is open.');
        }
        $chunk = \deflate_add($this->deflate, '', \ZLIB_FINISH);
        $this->deflate = null;
        if ($chunk === false) {
            throw new IoException('Deflate failed.');
        }
        if ($this->headerBytesToStrip > 0) {
            $chunk = $this->stripHeader($chunk);
        }
        // The gzip trailer is the CRC-32 followed by the size modulo 2^32, both little-endian.
        $trailer = \unpack('Vcrc', $chunk, \strlen($chunk) - self::GZIP_TRAILER_LENGTH);
        if ($this->headerBytesToStrip > 0 || $trailer === false) {
            throw new IoException('Unexpected output from the deflate compressor.');
        }
        /** @var int $crc */
        $crc = $trailer['crc'];
        $chunk = \substr($chunk, 0, -self::GZIP_TRAILER_LENGTH);
        $this->compressed += \strlen($chunk);

        $zip64 = $this->compressed > $this->zip64SizeLimit || $this->uncompressed > $this->zip64SizeLimit;
        $this->emit(
            $chunk . ($zip64
                ? \pack('VVPP', 0x08074B50, $crc, $this->compressed, $this->uncompressed)
                : \pack('VVVV', 0x08074B50, $crc, $this->compressed, $this->uncompressed)),
        );
        $this->central[] = [
            'name' => $this->entryName,
            'crc' => $crc,
            'compressed' => $this->compressed,
            'uncompressed' => $this->uncompressed,
            'offset' => $this->entryOffset,
        ];
    }

    /**
     * Writes the central directory and hands everything that is left to the sink.
     */
    public function finish(): void
    {
        if ($this->deflate !== null || $this->finished) {
            throw new WriterStateException('The archive cannot be finished in its current state.');
        }
        $this->finished = true;
        $limit = $this->zip64SizeLimit;
        $directoryStart = $this->position;

        foreach ($this->central as $record) {
            $zip64Sizes = $record['compressed'] > $limit || $record['uncompressed'] > $limit;
            $zip64Offset = $record['offset'] > $limit;
            $extra = '';
            if ($zip64Sizes || $zip64Offset) {
                $fields = ($zip64Sizes ? \pack('PP', $record['uncompressed'], $record['compressed']) : '')
                    . ($zip64Offset ? \pack('P', $record['offset']) : '');
                $extra = \pack('vv', 0x0001, \strlen($fields)) . $fields;
            }
            $this->emit(
                \pack(
                    'VvvvvvvVVVvvvvvVV',
                    0x02014B50,
                    self::VERSION_ZIP64,
                    $extra === '' ? self::VERSION_DEFAULT : self::VERSION_ZIP64,
                    self::FLAG_DATA_DESCRIPTOR,
                    self::METHOD_DEFLATE,
                    self::DOS_TIME,
                    self::DOS_DATE,
                    $record['crc'],
                    $zip64Sizes ? 0xFFFFFFFF : $record['compressed'],
                    $zip64Sizes ? 0xFFFFFFFF : $record['uncompressed'],
                    \strlen($record['name']),
                    \strlen($extra),
                    0,
                    0,
                    0,
                    0,
                    $zip64Offset ? 0xFFFFFFFF : $record['offset'],
                ) . $record['name'] . $extra,
            );
        }

        $directorySize = $this->position - $directoryStart;
        $count = \count($this->central);
        $tail = '';
        if ($count > $this->zip64CountLimit || $directorySize > $limit || $directoryStart > $limit) {
            $tail = \pack('VPvvVVPPPP', 0x06064B50, 44, self::VERSION_ZIP64, self::VERSION_ZIP64, 0, 0, $count, $count, $directorySize, $directoryStart)
                . \pack('VVPV', 0x07064B50, 0, $this->position, 1);
        }
        $tail .= \pack(
            'VvvvvVVv',
            0x06054B50,
            0,
            0,
            $count > $this->zip64CountLimit ? 0xFFFF : $count,
            $count > $this->zip64CountLimit ? 0xFFFF : $count,
            $directorySize > $limit ? 0xFFFFFFFF : $directorySize,
            $directoryStart > $limit ? 0xFFFFFFFF : $directoryStart,
            0,
        );
        $this->emit($tail);
        $this->flush();
    }

    public function flush(): void
    {
        if ($this->output !== '') {
            $output = $this->output;
            $this->output = '';
            $this->sink->write($output);
        }
    }

    private function emit(string $bytes): void
    {
        $this->output .= $bytes;
        $this->position += \strlen($bytes);
        if (\strlen($this->output) >= $this->flushThreshold) {
            $this->flush();
        }
    }

    private function stripHeader(string $chunk): string
    {
        $strip = \min($this->headerBytesToStrip, \strlen($chunk));
        $this->headerBytesToStrip -= $strip;

        return \substr($chunk, $strip);
    }
}
