<?php

declare(strict_types=1);

namespace Vensas\Hypaxel\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Vensas\Hypaxel\ColumnSpec;
use Vensas\Hypaxel\Options;
use Vensas\Hypaxel\Tests\Support\XlsxTestCase;
use Vensas\Hypaxel\Xlsx;

final class XlsxHelpersTest extends XlsxTestCase
{
    private const RECORDS = [
        ['id' => 1, 'customer' => 'Ada'],
        ['id' => 2, 'customer' => 'Linus'],
    ];

    public function testWriteFile(): void
    {
        $path = $this->tempFile();
        Xlsx::writeFile($path, self::RECORDS, sheetName: 'Orders', columns: ['customer', new ColumnSpec('id', header: 'No.')]);
        $sheet = $this->loadSheet((string) \file_get_contents($path));

        self::assertSame('Orders', $sheet->getTitle());
        self::assertSame([['customer', 'No.'], ['Ada', 1], ['Linus', 2]], self::values($sheet));
    }

    public function testWriteStream(): void
    {
        $stream = \fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        Xlsx::writeStream($stream, [[1, 2], [3, 4]], header: false, options: new Options(cellReferences: true));
        \rewind($stream);
        $bytes = (string) \stream_get_contents($stream);
        \fclose($stream);

        self::assertSame([[1, 2], [3, 4]], self::values($this->loadSheet($bytes)));
        self::assertStringContainsString('<c r="B2">', $this->part($bytes, 'xl/worksheets/sheet1.xml'));
    }

    public function testToString(): void
    {
        $bytes = Xlsx::toString(self::RECORDS, autoFilter: false);
        $sheet = $this->loadSheet($bytes);

        self::assertStringStartsWith('PK', $bytes);
        self::assertSame('Sheet1', $sheet->getTitle());
        self::assertSame([['id', 'customer'], [1, 'Ada'], [2, 'Linus']], self::values($sheet));
        self::assertSame('', $sheet->getAutoFilter()->getRange());
    }

    public function testStreamYieldsChunksWhileConsumingTheSourceLazily(): void
    {
        $produced = 0;
        $source = (static function () use (&$produced): \Generator {
            for ($i = 0; $i < 20_000; ++$i) {
                ++$produced;
                yield ['id' => $i, 'text' => 'Row number ' . $i . ' ' . \md5((string) $i)];
            }
        })();
        $bytes = '';
        $producedAtFirstChunk = null;
        $chunks = 0;
        foreach (Xlsx::stream($source, options: new Options(flushThreshold: 4096)) as $chunk) {
            $producedAtFirstChunk ??= $produced;
            self::assertNotSame('', $chunk);
            $bytes .= $chunk;
            ++$chunks;
        }
        $sheet = $this->loadSheet($bytes);

        self::assertGreaterThan(10, $chunks);
        self::assertLessThan(20_000, $producedAtFirstChunk);
        self::assertSame(20_001, $sheet->getHighestRow());
        self::assertSame('A1:B20001', $sheet->getAutoFilter()->getRange());
        self::assertSame(19_999, self::value($sheet, 'A20001'));
    }

    public function testStreamOfNothingIsAValidWorkbook(): void
    {
        $bytes = \implode('', \iterator_to_array(Xlsx::stream([], sheetName: 'Nothing'), false));

        self::assertSame(['Nothing'], $this->load($bytes)->getSheetNames());
    }

    public function testOutputEchoesTheWorkbook(): void
    {
        \ob_start();
        Xlsx::output(self::RECORDS, sheetName: 'Echo');
        $bytes = (string) \ob_get_clean();

        self::assertSame([['id', 'customer'], [1, 'Ada'], [2, 'Linus']], self::values($this->loadSheet($bytes)));
    }

    public function testDownloadSendsHeadersAndTheWorkbook(): void
    {
        \ob_start();
        try {
            Xlsx::download('orders.xlsx', self::RECORDS);
        } finally {
            $bytes = (string) \ob_get_clean();
            \header_remove();
        }

        self::assertStringStartsWith('PK', $bytes);
        self::assertSame('Ada', self::value($this->loadSheet($bytes), 'B2'));
    }

    public function testHeaders(): void
    {
        self::assertSame([
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="orders.xlsx"',
            'X-Content-Type-Options' => 'nosniff',
        ], Xlsx::headers('orders.xlsx'));
        self::assertSame(Xlsx::CONTENT_TYPE, Xlsx::headers('x')['Content-Type']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function filenames(): iterable
    {
        yield 'plain' => ['orders.xlsx', 'attachment; filename="orders.xlsx"'];
        yield 'extension added' => ['orders', 'attachment; filename="orders.xlsx"'];
        yield 'upper-case extension kept' => ['ORDERS.XLSX', 'attachment; filename="ORDERS.XLSX"'];
        yield 'empty' => ['', 'attachment; filename="export.xlsx"'];
        yield 'only an extension' => ['.xlsx', 'attachment; filename="export.xlsx"'];
        yield 'path is dropped' => ['/var/www/../secret/report.xlsx', 'attachment; filename="report.xlsx"'];
        yield 'header injection' => ["a\r\nSet-Cookie: x=1\".xlsx", 'attachment; filename="aSet-Cookie: x=1.xlsx"'];
        yield 'non-ascii' => ['Übersicht 2024 €.xlsx', "attachment; filename=\"_bersicht 2024 _.xlsx\"; filename*=UTF-8''%C3%9Cbersicht%202024%20%E2%82%AC.xlsx"];
        yield 'percent sign' => ['100%.xlsx', "attachment; filename=\"100_.xlsx\"; filename*=UTF-8''100%25.xlsx"];
    }

    #[DataProvider('filenames')]
    public function testContentDisposition(string $filename, string $expected): void
    {
        self::assertSame($expected, Xlsx::contentDisposition($filename));
    }
}
