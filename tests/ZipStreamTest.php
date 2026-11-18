<?php

declare(strict_types=1);

namespace Vensas\Hypaxel\Tests;

use Vensas\Hypaxel\Exception\WriterStateException;
use Vensas\Hypaxel\Internal\ZipStream;
use Vensas\Hypaxel\Sink\MemorySink;
use Vensas\Hypaxel\Tests\Support\XlsxTestCase;

final class ZipStreamTest extends XlsxTestCase
{
    public function testEntriesRoundTripThroughZipArchive(): void
    {
        $big = \str_repeat('<x>0123456789</x>', 50_000);
        $random = \random_bytes(200_000);
        $sink = new MemorySink();
        $zip = new ZipStream($sink, 1, 4096);
        $zip->startEntry('a.txt');
        $zip->write('hello ');
        $zip->write('');
        $zip->write('world');
        $zip->finishEntry();
        $zip->startEntry('dir/b.xml');
        foreach (\str_split($big, 70_000) as $chunk) {
            $zip->write($chunk);
        }
        $zip->finishEntry();
        $zip->startEntry('empty');
        $zip->finishEntry();
        $zip->startEntry('random.bin');
        $zip->write($random);
        $zip->finishEntry();
        $zip->finish();
        $bytes = $sink->take();

        self::assertSame(\strlen($bytes), $zip->position());
        self::assertSame(['a.txt', 'dir/b.xml', 'empty', 'random.bin'], $this->partNames($bytes));
        self::assertSame('hello world', $this->part($bytes, 'a.txt'));
        self::assertSame($big, $this->part($bytes, 'dir/b.xml'));
        self::assertSame($random, $this->part($bytes, 'random.bin'));

        $archive = new \ZipArchive();
        self::assertTrue($archive->open($this->tempFile($bytes), \ZipArchive::CHECKCONS));
        self::assertSame('', $archive->getFromName('empty'));
        $stat = $archive->statName('dir/b.xml');
        self::assertIsArray($stat);
        self::assertSame(\strlen($big), $stat['size']);
        self::assertSame(\crc32($big), $stat['crc']);
        self::assertSame(\ZipArchive::CM_DEFLATE, $stat['comp_method']);
        self::assertSame(\crc32($random), $archive->statName('random.bin')['crc'] ?? null);
        $archive->close();

        self::assertStringStartsWith("PK\x03\x04\x14\x00\x08\x00\x08\x00\x00\x00\x21\x00", $bytes);
        self::assertStringNotContainsString("PK\x06\x06", $bytes);
    }

    public function testEveryCompressionLevelProducesTheSameContent(): void
    {
        $data = \str_repeat('The quick brown fox jumps over the lazy dog. ', 5000);
        $sizes = [];
        foreach ([0, 1, 6, 9] as $level) {
            $sink = new MemorySink();
            $zip = new ZipStream($sink, $level, 1 << 20);
            $zip->startEntry('data.txt');
            $zip->write($data);
            $zip->finishEntry();
            $zip->finish();
            $bytes = $sink->take();
            $sizes[$level] = \strlen($bytes);
            self::assertSame($data, $this->part($bytes, 'data.txt'));
        }

        self::assertGreaterThan(\strlen($data), $sizes[0]);
        self::assertLessThan($sizes[0] / 10, $sizes[1]);
        self::assertLessThanOrEqual($sizes[1], $sizes[9]);
    }

    public function testOutputIsHandedOverInChunksOfTheFlushThreshold(): void
    {
        $chunks = [];
        $sink = new \Vensas\Hypaxel\Sink\CallbackSink(static function (string $bytes) use (&$chunks): void {
            $chunks[] = \strlen($bytes);
        });
        $zip = new ZipStream($sink, 0, 1024);
        $zip->startEntry('data.bin');
        for ($i = 0; $i < 400; ++$i) {
            $zip->write(\random_bytes(1000));
        }
        self::assertGreaterThan(3, \count($chunks));
        $zip->finishEntry();
        $zip->finish();

        self::assertSame($zip->position(), \array_sum($chunks));
        foreach (\array_slice($chunks, 0, -1) as $size) {
            self::assertGreaterThanOrEqual(1024, $size);
        }
    }

    public function testMisuseIsRejected(): void
    {
        $zip = new ZipStream(new MemorySink(), 1, 1024);

        foreach ([
            static fn() => $zip->write('x'),
            static fn() => $zip->finishEntry(),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Expected a WriterStateException.');
            } catch (WriterStateException $e) {
                self::assertSame('No ZIP entry is open.', $e->getMessage());
            }
        }
        $zip->startEntry('a');
        foreach ([static fn() => $zip->startEntry('b'), static fn() => $zip->finish()] as $attempt) {
            try {
                $attempt();
                self::fail('Expected a WriterStateException.');
            } catch (WriterStateException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        $zip->finishEntry();
        $zip->finish();

        $this->expectException(WriterStateException::class);
        $zip->startEntry('late');
    }

    public function testZip64RecordsForLargeSizesAndOffsets(): void
    {
        // Limits far below 4 GiB exercise the same code paths with the real record layouts.
        $limit = 1000;
        $small = 'tiny';
        $large = \str_repeat('0123456789', 400);
        $noise = \random_bytes(3000);
        $sink = new MemorySink();
        $zip = new ZipStream($sink, 1, 1 << 20, $limit, 0xFFFF);
        foreach (['small.txt' => $small, 'large.txt' => $large, 'noise.bin' => $noise, 'late.txt' => $small, 'more.bin' => $noise] as $name => $data) {
            $zip->startEntry($name);
            $zip->write($data);
            $zip->finishEntry();
        }
        $zip->finish();
        $bytes = $sink->take();

        $end = \strrpos($bytes, "PK\x05\x06");
        self::assertIsInt($end);
        $eocd = \unpack('Vsig/vdisk/vcdDisk/vdiskEntries/ventries/VcdSize/VcdOffset/vcomment', $bytes, $end);
        self::assertIsArray($eocd);
        self::assertSame(5, $eocd['entries']);
        self::assertSame(0xFFFFFFFF, $eocd['cdOffset']);
        self::assertSame(\strlen($bytes), $end + 22);

        $locatorAt = $end - 20;
        $locator = \unpack('Vsig/Vdisk/Poffset/Vdisks', $bytes, $locatorAt);
        self::assertIsArray($locator);
        self::assertSame(0x07064B50, $locator['sig']);
        self::assertSame(1, $locator['disks']);
        self::assertIsInt($locator['offset']);

        $eocd64 = \unpack('Vsig/Psize/vmadeBy/vneeded/Vdisk/VcdDisk/PdiskEntries/Pentries/PcdSize/PcdOffset', $bytes, $locator['offset']);
        self::assertIsArray($eocd64);
        self::assertSame(0x06064B50, $eocd64['sig']);
        self::assertSame(44, $eocd64['size']);
        self::assertSame(45, $eocd64['needed']);
        self::assertSame(5, $eocd64['entries']);
        self::assertSame(5, $eocd64['diskEntries']);
        self::assertIsInt($eocd64['cdOffset']);
        self::assertSame($locator['offset'] - $eocd64['cdOffset'], $eocd64['cdSize']);
        self::assertSame($locatorAt, $locator['offset'] + 56);

        [$first, $second, $third, $fourth, $fifth] = self::centralDirectory($bytes, $eocd64['cdOffset'], 5);
        self::assertSame(['small.txt', 'large.txt', 'noise.bin', 'late.txt', 'more.bin'], [$first['name'], $second['name'], $third['name'], $fourth['name'], $fifth['name']]);

        self::assertSame(20, $first['needed']);
        self::assertSame('', $first['extra']);
        self::assertSame(\strlen($small), $first['uncompressed']);
        self::assertSame(0, $first['offset']);
        self::assertGreaterThan(0, self::deflatedSize($bytes, 0, 'small.txt', false));

        // Only the uncompressed size is over the limit, yet both sizes move to the extra field.
        self::assertSame(45, $second['needed']);
        self::assertSame(0xFFFFFFFF, $second['compressed']);
        self::assertSame(0xFFFFFFFF, $second['uncompressed']);
        self::assertLessThan($limit, $second['offset']);
        self::assertSame(\crc32($large), $second['crc']);
        self::assertSame(
            ['id' => 1, 'size' => 16, 'uncompressed' => \strlen($large), 'compressed' => self::deflatedSize($bytes, $second['offset'], 'large.txt', true)],
            \unpack('vid/vsize/Puncompressed/Pcompressed', $second['extra']),
        );

        // Both sizes over the limit, offset still below it.
        self::assertLessThan($limit, $third['offset']);
        self::assertSame(\crc32($noise), $third['crc']);
        self::assertSame(
            ['id' => 1, 'size' => 16, 'uncompressed' => \strlen($noise), 'compressed' => self::deflatedSize($bytes, $third['offset'], 'noise.bin', true)],
            \unpack('vid/vsize/Puncompressed/Pcompressed', $third['extra']),
        );

        // Only the offset is over the limit.
        self::assertSame(45, $fourth['needed']);
        self::assertSame(\strlen($small), $fourth['uncompressed']);
        self::assertSame(0xFFFFFFFF, $fourth['offset']);
        $extra = \unpack('vid/vsize/Poffset', $fourth['extra']);
        self::assertIsArray($extra);
        self::assertSame(8, $extra['size']);
        self::assertIsInt($extra['offset']);
        self::assertGreaterThan($limit, $extra['offset']);
        self::assertSame("PK\x03\x04", \substr($bytes, $extra['offset'], 4));
        self::assertSame('late.txt', \substr($bytes, $extra['offset'] + 30, 8));
        self::assertGreaterThan(0, self::deflatedSize($bytes, $extra['offset'], 'late.txt', false));

        // Sizes and offset over the limit.
        self::assertSame(0xFFFFFFFF, $fifth['compressed']);
        self::assertSame(0xFFFFFFFF, $fifth['offset']);
        $extra = \unpack('vid/vsize/Puncompressed/Pcompressed/Poffset', $fifth['extra']);
        self::assertIsArray($extra);
        self::assertSame(24, $extra['size']);
        self::assertSame(\strlen($noise), $extra['uncompressed']);
        self::assertIsInt($extra['offset']);
        self::assertSame($extra['compressed'], self::deflatedSize($bytes, $extra['offset'], 'more.bin', true));
    }

    public function testForcedZip64ArchivesAreReadByLibzip(): void
    {
        $data = \str_repeat('spreadsheet ', 2000);
        $sink = new MemorySink();
        $zip = new ZipStream($sink, 6, 1 << 20, 100, 1);
        foreach (['one.xml', 'two.xml', 'three.xml'] as $name) {
            $zip->startEntry($name);
            $zip->write($data);
            $zip->finishEntry();
        }
        $zip->finish();
        $bytes = $sink->take();

        self::assertSame(['one.xml', 'two.xml', 'three.xml'], $this->partNames($bytes));
        self::assertSame($data, $this->part($bytes, 'three.xml'));
    }

    public function testZip64EndRecordForMoreThan65535Entries(): void
    {
        $count = 65_600;
        $sink = new MemorySink();
        $zip = new ZipStream($sink, 0, 1 << 20);
        for ($i = 0; $i < $count; ++$i) {
            $zip->startEntry('e' . $i);
            $zip->finishEntry();
        }
        $zip->finish();
        $bytes = $sink->take();

        $end = \strrpos($bytes, "PK\x05\x06");
        self::assertIsInt($end);
        $eocd = \unpack('Vsig/vdisk/vcdDisk/vdiskEntries/ventries/VcdSize/VcdOffset', $bytes, $end);
        self::assertIsArray($eocd);
        self::assertSame(0xFFFF, $eocd['entries']);
        self::assertSame(0xFFFF, $eocd['diskEntries']);
        self::assertStringContainsString("PK\x06\x07", $bytes);

        $archive = new \ZipArchive();
        self::assertTrue($archive->open($this->tempFile($bytes), \ZipArchive::CHECKCONS));
        self::assertSame($count, $archive->numFiles);
        self::assertSame('e65599', $archive->getNameIndex($count - 1));
        self::assertSame('', $archive->getFromName('e12345'));
        $archive->close();
    }

    /**
     * @return list<array{name: string, needed: int, crc: int, compressed: int, uncompressed: int, offset: int, extra: string}>
     */
    private static function centralDirectory(string $bytes, int $offset, int $count): array
    {
        $entries = [];
        for ($i = 0; $i < $count; ++$i) {
            $h = \unpack('Vsig/vmadeBy/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vnameLength/vextraLength/vcommentLength/vdisk/vinternal/Vexternal/Voffset', $bytes, $offset);
            self::assertIsArray($h);
            self::assertSame(0x02014B50, $h['sig']);
            self::assertSame(8, $h['flags']);
            self::assertSame(8, $h['method']);
            self::assertIsInt($h['nameLength']);
            self::assertIsInt($h['extraLength']);
            self::assertIsInt($h['needed']);
            self::assertIsInt($h['crc']);
            self::assertIsInt($h['compressed']);
            self::assertIsInt($h['uncompressed']);
            self::assertIsInt($h['offset']);
            $entries[] = [
                'name' => \substr($bytes, $offset + 46, $h['nameLength']),
                'needed' => $h['needed'],
                'crc' => $h['crc'],
                'compressed' => $h['compressed'],
                'uncompressed' => $h['uncompressed'],
                'offset' => $h['offset'],
                'extra' => \substr($bytes, $offset + 46 + $h['nameLength'], $h['extraLength']),
            ];
            $offset += 46 + $h['nameLength'] + $h['extraLength'];
        }

        return $entries;
    }

    /**
     * Inflates the entry at `$offset` and returns its compressed size after checking the data
     * descriptor that follows it.
     */
    private static function deflatedSize(string $bytes, int $offset, string $name, bool $zip64): int
    {
        $dataStart = $offset + 30 + \strlen($name);
        $context = \inflate_init(\ZLIB_ENCODING_RAW);
        self::assertNotFalse($context);
        $data = \inflate_add($context, \substr($bytes, $dataStart));
        self::assertIsString($data);
        self::assertSame(\ZLIB_STREAM_END, \inflate_get_status($context));
        $compressed = \inflate_get_read_len($context);

        $descriptor = \unpack($zip64 ? 'Vsig/Vcrc/Pcompressed/Puncompressed' : 'Vsig/Vcrc/Vcompressed/Vuncompressed', $bytes, $dataStart + $compressed);
        self::assertSame(['sig' => 0x08074B50, 'crc' => \crc32($data), 'compressed' => $compressed, 'uncompressed' => \strlen($data)], $descriptor);

        return $compressed;
    }
}
