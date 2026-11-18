<?php

declare(strict_types=1);

namespace Vensas\Hypaxel\Tests;

use PhpOffice\PhpSpreadsheet\Style\Fill;
use Vensas\Hypaxel\ColumnSpec;
use Vensas\Hypaxel\Exception\InvalidArgumentException;
use Vensas\Hypaxel\Exception\UnsupportedValueException;
use Vensas\Hypaxel\Options;
use Vensas\Hypaxel\Tests\Support\Order;
use Vensas\Hypaxel\Tests\Support\XlsxTestCase;
use Vensas\Hypaxel\Writer;

final class RecordsTest extends XlsxTestCase
{
    public function testAssociativeArraysGetAStyledFrozenHeaderAndAutoFilter(): void
    {
        $writer = Writer::inMemory();
        $count = $writer->writeWorksheet('Orders', [
            ['id' => 1, 'customer' => 'Ada', 'amount' => 1234.5],
            ['id' => 2, 'customer' => 'Linus', 'amount' => 99.99, 'ignored' => 'x'],
            ['customer' => 'Grace', 'id' => 3],
        ]);
        $bytes = $writer->finish();
        $sheet = $this->loadSheet($bytes);

        self::assertSame(3, $count);
        self::assertSame('Orders', $sheet->getTitle());
        self::assertSame([
            ['id', 'customer', 'amount'],
            [1, 'Ada', 1234.5],
            [2, 'Linus', 99.99],
            [3, 'Grace', null],
        ], self::values($sheet));
        self::assertTrue($sheet->getStyle('A1')->getFont()->getBold());
        self::assertSame(Fill::FILL_SOLID, $sheet->getStyle('C1')->getFill()->getFillType());
        self::assertSame('FFD9D9D9', $sheet->getStyle('C1')->getFill()->getStartColor()->getARGB());
        self::assertSame('thin', $sheet->getStyle('B1')->getBorders()->getBottom()->getBorderStyle());
        self::assertFalse($sheet->getStyle('A2')->getFont()->getBold());
        self::assertSame('A2', $sheet->getFreezePane());
        self::assertSame('A1:C4', $sheet->getAutoFilter()->getRange());
        self::assertSame(12.0, $sheet->getColumnDimension('A')->getWidth());
    }

    public function testColumnSpecsSelectOrderAndFormat(): void
    {
        $writer = Writer::inMemory();
        $writer->writeWorksheet('Orders', [
            ['id' => 1, 'customer' => 'Ada', 'amount' => 1234.5, 'placed' => new \DateTimeImmutable('2024-05-01')],
            ['id' => 2, 'customer' => 'Linus', 'amount' => null, 'placed' => null],
        ], columns: [
            new ColumnSpec('customer', header: 'Customer name with a rather long header text', width: 30.0),
            'id',
            new ColumnSpec('amount', header: 'Amount', numberFormat: '#,##0.00'),
            new ColumnSpec('placed', numberFormat: 'yyyy-mm-dd'),
            new ColumnSpec('initial', value: static fn(mixed $r): string => \is_array($r) && \is_string($r['customer']) ? $r['customer'][0] : '?'),
        ]);
        $sheet = $this->loadSheet($writer->finish());

        self::assertSame(['Customer name with a rather long header text', 'id', 'Amount', 'placed', 'initial'], self::values($sheet)[0]);
        self::assertSame(['Ada', 1, 1234.5, 45413, 'A'], self::values($sheet)[1]);
        self::assertSame(['Linus', 2, null, null, 'L'], self::values($sheet)[2]);
        self::assertSame('#,##0.00', $sheet->getStyle('C2')->getNumberFormat()->getFormatCode());
        self::assertSame('#,##0.00', $sheet->getStyle('C3')->getNumberFormat()->getFormatCode());
        self::assertSame('yyyy-mm-dd', $sheet->getStyle('D2')->getNumberFormat()->getFormatCode());
        self::assertSame('General', $sheet->getStyle('A2')->getNumberFormat()->getFormatCode());
        self::assertSame(30.0, $sheet->getColumnDimension('A')->getWidth());
        self::assertSame(12.0, $sheet->getColumnDimension('B')->getWidth());
        self::assertSame('A1:E3', $sheet->getAutoFilter()->getRange());
    }

    public function testHeaderWidthsAreEstimatedFromTheHeaderText(): void
    {
        $writer = Writer::inMemory();
        $writer->writeWorksheet('Widths', [['short' => 1, 'a header of thirty characters!' => 2, \str_repeat('x', 80) => 3]]);
        $sheet = $this->loadSheet($writer->finish());

        self::assertSame(12.0, $sheet->getColumnDimension('A')->getWidth());
        self::assertSame(33.0, $sheet->getColumnDimension('B')->getWidth());
        self::assertSame(60.0, $sheet->getColumnDimension('C')->getWidth());
    }

    public function testObjectsAreReadThroughPublicPropertiesAndCallables(): void
    {
        $orders = [
            new Order(1, 'Ada', 10.5, new \DateTimeImmutable('2024-05-01')),
            new Order(2, 'Linus', 20.25),
        ];
        $writer = Writer::inMemory();
        $writer->writeWorksheet('Defaults', $orders);
        $writer->writeWorksheet('Explicit', new \ArrayIterator($orders), columns: [
            'customer',
            'missing',
            new ColumnSpec('note', header: 'Note', value: static fn(mixed $o): string => $o instanceof Order ? $o->note() : ''),
        ]);
        $workbook = $this->load($writer->finish());

        self::assertSame([
            ['id', 'customer', 'amount', 'placed'],
            [1, 'Ada', 10.5, 45413],
            [2, 'Linus', 20.25, null],
        ], self::values($workbook->getSheet(0)));
        self::assertSame([
            ['customer', 'missing', 'Note'],
            ['Ada', null, 'internal'],
            ['Linus', null, 'internal'],
        ], self::values($workbook->getSheet(1)));
    }

    public function testArrayAccessAndStdClassRecords(): void
    {
        $writer = Writer::inMemory();
        $writer->writeWorksheet('ArrayAccess', [new \ArrayObject(['a' => 1, 'b' => 'x'])], columns: ['b', 'a', 'c']);
        $writer->writeWorksheet('StdClass', [(object) ['a' => 1, 'b' => 'x'], (object) ['b' => 'y']]);
        $workbook = $this->load($writer->finish());

        self::assertSame([['b', 'a', 'c'], ['x', 1, null]], self::values($workbook->getSheet(0)));
        self::assertSame([['a', 'b'], [1, 'x'], [null, 'y']], self::values($workbook->getSheet(1)));
    }

    public function testRowsAreWrittenAsTheyAreAndColumnsBecomeHeaders(): void
    {
        $writer = Writer::inMemory();
        $writer->writeWorksheet('Plain', [[1, 'a'], [2, 'b']]);
        $writer->writeWorksheet('Headed', (static fn(): \Generator => yield from [[1, 'a'], [2, 'b']])(), columns: ['Id', new ColumnSpec('label', header: 'Label')]);
        $writer->writeWorksheet('No filter', [[1]], columns: ['Id'], autoFilter: false);
        $writer->writeWorksheet('No header', [['id' => 7]], header: false);
        $workbook = $this->load($writer->finish());

        self::assertSame([[1, 'a'], [2, 'b']], self::values($workbook->getSheet(0)));
        self::assertNull($workbook->getSheet(0)->getFreezePane());
        self::assertSame('', $workbook->getSheet(0)->getAutoFilter()->getRange());
        self::assertSame([['Id', 'Label'], [1, 'a'], [2, 'b']], self::values($workbook->getSheet(1)));
        self::assertSame('A2', $workbook->getSheet(1)->getFreezePane());
        self::assertSame('A1:B3', $workbook->getSheet(1)->getAutoFilter()->getRange());
        self::assertSame('', $workbook->getSheet(2)->getAutoFilter()->getRange());
        self::assertSame('A2', $workbook->getSheet(2)->getFreezePane());
        self::assertSame([[7]], self::values($workbook->getSheet(3)));
        self::assertNull($workbook->getSheet(3)->getFreezePane());
    }

    public function testEmptyDataStillProducesAWorksheet(): void
    {
        $writer = Writer::inMemory();
        self::assertSame(0, $writer->writeWorksheet('Empty', []));
        self::assertSame(0, $writer->writeWorksheet('Header only', new \EmptyIterator(), columns: ['id', 'name']));
        $workbook = $this->load($writer->finish());

        self::assertSame(['Empty', 'Header only'], $workbook->getSheetNames());
        self::assertSame([['id', 'name']], self::values($workbook->getSheet(1)));
        self::assertSame('A1:B1', $workbook->getSheet(1)->getAutoFilter()->getRange());
    }

    public function testWriteRecordsOnAWorksheetWritesNoHeader(): void
    {
        $writer = Writer::inMemory(new Options(sharedStrings: true));
        $sheet = $writer->addWorksheet('Raw');
        $sheet->writeRow(['custom header']);
        $count = $sheet->writeRecords([['a' => 1, 'b' => 'x'], ['a' => 2, 'b' => 'x']], ['b', new ColumnSpec('a', numberFormat: '0.0')]);
        $loaded = $this->loadSheet($writer->finish());

        self::assertSame(2, $count);
        self::assertSame([['custom header', null], ['x', 1], ['x', 2]], self::values($loaded));
        self::assertSame('0.0', $loaded->getStyle('B2')->getNumberFormat()->getFormatCode());
    }

    public function testUnusableRecordsAreRejected(): void
    {
        $sheet = Writer::inMemory()->addWorksheet('Bad');

        try {
            /** @phpstan-ignore argument.type */
            $sheet->writeRecords([['a' => 1]], []);
            self::fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('At least one column', $e->getMessage());
        }
        try {
            $sheet->writeRecords(['scalar'], ['a']);
            self::fail('Expected an UnsupportedValueException.');
        } catch (UnsupportedValueException $e) {
            self::assertStringContainsString('A record must be an array or an object, string given', $e->getMessage());
        }

        $this->expectException(UnsupportedValueException::class);
        $this->expectExceptionMessage('pass the columns explicitly');
        Writer::inMemory()->writeWorksheet('NoKeys', [new \ArrayObject(['a' => 1])]);
    }
}
