<?php

declare(strict_types=1);

namespace Vensas\Hypaxel\Tests;

use OpenSpout\Reader\XLSX\Reader;
use Vensas\Hypaxel\Options;
use Vensas\Hypaxel\Tests\Support\XlsxTestCase;
use Vensas\Hypaxel\Writer;
use Vensas\Hypaxel\Xlsx;

final class StreamingTest extends XlsxTestCase
{
    public function testPeakMemoryDoesNotGrowWithTheRowCount(): void
    {
        $small = self::peakMemoryOfExport(20_000, new Options());
        $large = self::peakMemoryOfExport(400_000, new Options());

        self::assertLessThan(1 << 20, $small);
        self::assertLessThan(256 * 1024, $large - $small, \sprintf('20k rows: %d bytes, 400k rows: %d bytes', $small, $large));
    }

    public function testSharedStringsAreTheDocumentedExceptionToConstantMemory(): void
    {
        $inline = self::peakMemoryOfExport(50_000, new Options());
        $shared = self::peakMemoryOfExport(50_000, new Options(sharedStrings: true));

        self::assertGreaterThan($inline + (1 << 20), $shared);
    }

    public function testTheFirstBytesLeaveBeforeTheSourceIsExhausted(): void
    {
        $received = 0;
        $receivedDuringIteration = 0;
        $writer = Writer::toCallback(static function (string $bytes) use (&$received): void {
            $received += \strlen($bytes);
        });
        $rows = (static function () use (&$received, &$receivedDuringIteration): \Generator {
            for ($i = 0; $i < 50_000; ++$i) {
                yield [$i, 'Row ' . $i, $i * 1.5];
            }
            $receivedDuringIteration = $received;
        })();
        $writer->addWorksheet('Live')->writeRows($rows);
        $writer->finish();

        self::assertGreaterThan(100_000, $receivedDuringIteration);
        self::assertSame($writer->bytesWritten(), $received);
    }

    public function testOpenSpoutReadsCompactOutputIncludingGaps(): void
    {
        $path = $this->tempFile();
        $rows = [
            ['id', 'name', 'ratio', 'active', 'when'],
            [1, 'Ada <&>', 0.5, true, new \DateTimeImmutable('2024-05-01 12:00:00', new \DateTimeZone('UTC'))],
            [2, null, null, false, null],
            [null, 'tail', 3.25],
        ];
        $writer = Writer::toFile($path);
        $sheet = $writer->addWorksheet('Compact');
        $sheet->writeRows($rows);
        $sheet->skipRows(1);
        $sheet->writeRow(['after a gap']);
        $writer->addWorksheet('Second')->writeRow(['x']);
        $writer->finish();

        $reader = new Reader();
        $reader->open($path);
        $read = [];
        foreach ($reader->getSheetIterator() as $loaded) {
            foreach ($loaded->getRowIterator() as $index => $row) {
                self::assertIsInt($index);
                $read[$loaded->getName()][$index] = $row->toArray();
            }
        }
        $reader->close();

        self::assertSame(['Compact', 'Second'], \array_keys($read));
        self::assertSame(['id', 'name', 'ratio', 'active', 'when'], $read['Compact'][1]);
        self::assertSame([1, 'Ada <&>', 0.5, true], \array_slice($read['Compact'][2], 0, 4));
        self::assertInstanceOf(\DateTimeInterface::class, $read['Compact'][2][4]);
        self::assertSame('2024-05-01 12:00:00', $read['Compact'][2][4]->format('Y-m-d H:i:s'));
        self::assertSame([2, '', '', false], \array_slice($read['Compact'][3], 0, 4));
        self::assertSame(['', 'tail', 3.25], \array_slice($read['Compact'][4], 0, 3));
        self::assertSame(['after a gap'], \end($read['Compact']));
        self::assertSame(['x'], $read['Second'][1]);
    }

    public function testALargeExportIsReadBackCompletely(): void
    {
        $path = $this->tempFile();
        $count = 30_000;
        Xlsx::writeFile($path, (static function () use ($count): \Generator {
            for ($i = 1; $i <= $count; ++$i) {
                yield ['id' => $i, 'name' => 'Customer ' . ($i % 500), 'amount' => $i * 0.01, 'even' => $i % 2 === 0];
            }
        })());

        $reader = new Reader();
        $reader->open($path);
        $rows = 0;
        $sum = 0;
        $last = [];
        foreach ($reader->getSheetIterator() as $loaded) {
            foreach ($loaded->getRowIterator() as $index => $row) {
                ++$rows;
                $last = $row->toArray();
                if ($index > 1 && \is_int($last[0])) {
                    $sum += $last[0];
                }
            }
        }
        $reader->close();

        self::assertSame($count + 1, $rows);
        self::assertSame($count * ($count + 1) / 2, $sum);
        self::assertSame([$count, 'Customer 0', 300, true], $last);
    }

    private static function peakMemoryOfExport(int $rows, Options $options): int
    {
        $writer = Writer::toCallback(static fn(string $bytes): null => null, $options);
        $sheet = $writer->addWorksheet('Memory');
        $date = new \DateTimeImmutable('2024-01-01 08:30:00');
        \gc_collect_cycles();
        \memory_reset_peak_usage();
        $before = \memory_get_usage();
        $sheet->writeRows((static function () use ($rows, $date): \Generator {
            for ($i = 0; $i < $rows; ++$i) {
                yield [$i, 'Customer ' . ($i % 5000), $i * 1.25, $date, $i % 2 === 0, 'Unique comment #' . $i];
            }
        })());
        $writer->finish();

        return \memory_get_peak_usage() - $before;
    }
}
