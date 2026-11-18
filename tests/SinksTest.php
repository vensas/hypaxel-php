<?php

declare(strict_types=1);

namespace Vensas\Hypaxel\Tests;

use Vensas\Hypaxel\Exception\InvalidArgumentException;
use Vensas\Hypaxel\Exception\IoException;
use Vensas\Hypaxel\Exception\WriterStateException;
use Vensas\Hypaxel\Options;
use Vensas\Hypaxel\Sink\CallbackSink;
use Vensas\Hypaxel\Sink\MemorySink;
use Vensas\Hypaxel\Sink\Sink;
use Vensas\Hypaxel\Sink\StreamSink;
use Vensas\Hypaxel\Tests\Support\ChunkedStream;
use Vensas\Hypaxel\Tests\Support\XlsxTestCase;
use Vensas\Hypaxel\Writer;

final class SinksTest extends XlsxTestCase
{
    public function testToFileWritesAndClosesTheFile(): void
    {
        $path = $this->tempFile();
        $writer = Writer::toFile($path);
        $writer->addWorksheet('File')->writeRow(['on disk']);
        self::assertSame('', $writer->finish());

        self::assertSame($writer->bytesWritten(), \filesize($path));
        self::assertSame('on disk', self::value($this->loadSheet((string) \file_get_contents($path)), 'A1'));
    }

    public function testToFileReportsUnwritablePaths(): void
    {
        $this->expectException(IoException::class);
        $this->expectExceptionMessage('Cannot open');
        Writer::toFile(\sys_get_temp_dir() . '/hypaxel-missing-directory/sub/file.xlsx');
    }

    public function testToStreamLeavesTheStreamOpen(): void
    {
        $stream = \fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $writer = Writer::toStream($stream, new Options(flushThreshold: 1024));
        $writer->addWorksheet('Stream')->writeRows(self::rows(500));
        $writer->finish();

        self::assertSame('stream', \get_resource_type($stream));
        \rewind($stream);
        $bytes = (string) \stream_get_contents($stream);
        \fclose($stream);
        self::assertSame($writer->bytesWritten(), \strlen($bytes));
        self::assertSame('row 499', self::value($this->loadSheet($bytes), 'B500'));
    }

    public function testNonSeekableStreamsWithPartialWrites(): void
    {
        ChunkedStream::register();
        $stream = \fopen(ChunkedStream::SCHEME . '://sink', 'wb');
        self::assertIsResource($stream);
        \stream_set_write_buffer($stream, 0);

        $writer = Writer::toStream($stream, new Options(flushThreshold: 1024));
        $writer->addWorksheet('Slow')->writeRows(self::rows(300));
        $writer->finish();
        \fclose($stream);

        self::assertSame($writer->bytesWritten(), \strlen(ChunkedStream::$written));
        self::assertSame('row 299', self::value($this->loadSheet(ChunkedStream::$written), 'B300'));
    }

    public function testAStreamThatStopsAcceptingDataFails(): void
    {
        ChunkedStream::register();
        ChunkedStream::$failAfter = 2000;
        ChunkedStream::$maxChunk = 512;
        $stream = \fopen(ChunkedStream::SCHEME . '://sink', 'wb');
        self::assertIsResource($stream);
        \stream_set_write_buffer($stream, 0);
        $writer = Writer::toStream($stream, new Options(compressionLevel: 0, flushThreshold: 1024));
        $sheet = $writer->addWorksheet('Broken');

        try {
            $sheet->writeRows(self::rows(20_000));
            self::fail('Expected an IoException.');
        } catch (IoException $e) {
            self::assertSame('Writing to the destination stream failed.', $e->getMessage());
        } finally {
            \fclose($stream);
        }
    }

    public function testReadOnlyAndClosedStreamsFail(): void
    {
        $readOnly = \fopen(__FILE__, 'rb');
        self::assertIsResource($readOnly);
        $sink = new StreamSink($readOnly);
        try {
            $sink->write('data');
            self::fail('Expected an IoException.');
        } catch (IoException $e) {
            self::assertStringContainsString('Writing', $e->getMessage());
        } finally {
            \fclose($readOnly);
        }

        $closed = \fopen('php://memory', 'wb');
        self::assertIsResource($closed);
        \fclose($closed);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected an open stream resource');
        new StreamSink($closed);
    }

    public function testStreamSinkRejectsNonStreams(): void
    {
        $this->expectException(InvalidArgumentException::class);
        /** @phpstan-ignore argument.type (deliberately not a resource) */
        Writer::toStream('not a stream');
    }

    public function testCallbackSinkReceivesChunksInOrder(): void
    {
        $chunks = [];
        $closed = 0;
        $sink = new CallbackSink(
            static function (string $bytes) use (&$chunks): void {
                $chunks[] = $bytes;
            },
            static function () use (&$closed): void {
                ++$closed;
            },
        );
        $writer = new Writer($sink, new Options(flushThreshold: 1));
        self::assertSame(1024, (new Options(flushThreshold: 1))->flushThreshold);
        $writer->addWorksheet('Callback')->writeRows(self::rows(40_000));
        $writer->flush();
        $before = \count($chunks);
        $writer->finish();

        self::assertGreaterThan(5, $before);
        self::assertGreaterThan($before, \count($chunks));
        self::assertSame(1, $closed);
        $xml = $this->part(\implode('', $chunks), 'xl/worksheets/sheet1.xml');
        self::assertSame(40_000, \substr_count($xml, '<row '));
        self::assertStringContainsString('<t>row 39999</t>', $xml);
    }

    public function testToCallbackAcceptsAnyCallable(): void
    {
        $collector = new class {
            public string $bytes = '';

            public function write(string $bytes): int
            {
                $this->bytes .= $bytes;

                return \strlen($bytes);
            }
        };
        $writer = Writer::toCallback([$collector, 'write']);
        $writer->addWorksheet('Psr7 style')->writeRow([1]);
        $writer->finish();

        self::assertSame(1, self::value($this->loadSheet($collector->bytes), 'A1'));
    }

    public function testInMemoryWriterCanBeDrainedWhileWriting(): void
    {
        $writer = Writer::inMemory(new Options(flushThreshold: 2048));
        $sheet = $writer->addWorksheet('Drain');
        $bytes = '';
        $drains = 0;
        foreach (\array_chunk(\iterator_to_array(self::rows(30_000)), 2500) as $batch) {
            $sheet->writeRows($batch);
            $chunk = $writer->drain();
            $drains += $chunk === '' ? 0 : 1;
            $bytes .= $chunk;
        }
        $bytes .= $writer->finish();

        self::assertGreaterThan(3, $drains);
        self::assertSame(\strlen($bytes), $writer->bytesWritten());
        $xml = $this->part($bytes, 'xl/worksheets/sheet1.xml');
        self::assertSame(30_000, \substr_count($xml, '<row '));
        self::assertStringContainsString('<t>row 29999</t>', $xml);
    }

    public function testDrainNeedsAnInMemoryWriter(): void
    {
        $writer = Writer::toCallback(static fn(string $bytes): null => null);

        $this->expectException(WriterStateException::class);
        $writer->drain();
    }

    public function testMemorySink(): void
    {
        $sink = new MemorySink();
        self::assertTrue($sink->isEmpty());
        $sink->write('ab');
        $sink->write('cd');
        self::assertFalse($sink->isEmpty());
        self::assertSame('abcd', $sink->take());
        self::assertSame('', $sink->take());
        $sink->close();
        self::assertInstanceOf(Sink::class, $sink);
    }

    public function testOptionsClampTheirValues(): void
    {
        self::assertSame(9, (new Options(compressionLevel: 12))->compressionLevel);
        self::assertSame(0, (new Options(compressionLevel: -1))->compressionLevel);
        self::assertSame(1, (new Options())->compressionLevel);
        self::assertSame(65536, (new Options())->flushThreshold);
        self::assertSame(1 << 20, (new Options(flushThreshold: 1 << 20))->flushThreshold);
    }

    public function testCompressionLevelsTradeSizeForSpeed(): void
    {
        $sizes = [];
        foreach ([0, 1, 9] as $level) {
            $writer = Writer::inMemory(new Options(compressionLevel: $level));
            $writer->addWorksheet('Level')->writeRows(self::rows(2000));
            $bytes = $writer->finish();
            $sizes[$level] = \strlen($bytes);
            self::assertSame('row 1999', self::value($this->loadSheet($bytes), 'B2000'));
        }

        self::assertGreaterThan($sizes[1], $sizes[0]);
        self::assertGreaterThanOrEqual($sizes[9], $sizes[1]);
    }

    /**
     * @return \Generator<int, list<int|string|float>>
     */
    private static function rows(int $count): \Generator
    {
        for ($i = 0; $i < $count; ++$i) {
            yield [$i, 'row ' . $i, $i / 7];
        }
    }
}
