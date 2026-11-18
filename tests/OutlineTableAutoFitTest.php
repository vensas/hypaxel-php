<?php

declare(strict_types=1);

namespace Vensas\Hypaxel\Tests;

use Vensas\Hypaxel\AutoFit;
use Vensas\Hypaxel\Column;
use Vensas\Hypaxel\ColumnSpec;
use Vensas\Hypaxel\Exception\DuplicateTableNameException;
use Vensas\Hypaxel\Exception\InvalidArgumentException;
use Vensas\Hypaxel\Exception\InvalidTableNameException;
use Vensas\Hypaxel\Exception\OverlappingRangeException;
use Vensas\Hypaxel\Formula;
use Vensas\Hypaxel\NumberFormat;
use Vensas\Hypaxel\SheetOptions;
use Vensas\Hypaxel\Style;
use Vensas\Hypaxel\Styled;
use Vensas\Hypaxel\Table;
use Vensas\Hypaxel\TableStyle;
use Vensas\Hypaxel\Tests\Support\Suit;
use Vensas\Hypaxel\Tests\Support\XlsxTestCase;
use Vensas\Hypaxel\Writer;

final class OutlineTableAutoFitTest extends XlsxTestCase
{
    public function testRowOutlineLevels(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $sheet = $writer->addWorksheet('Tree', new SheetOptions(rowOutlineLevels: 2, outlineSummaryAbove: true));
            $sheet->writeRow(['North', 300]);
            $sheet->beginRow(outlineLevel: 1)->write('Store A')->write(120)->endRow();
            $sheet->beginRow(outlineLevel: 2, hidden: true)->write('Till 1')->write(60)->endRow();
            $sheet->beginRow(height: 20.0, outlineLevel: 1, collapsed: true)->write('Store B')->endRow();
            $sheet->writeRow(['South', 1]);
        });
        $xml = $this->part($bytes, 'xl/worksheets/sheet1.xml');

        self::assertStringContainsString('<sheetPr><outlinePr summaryBelow="0"/></sheetPr>', $xml);
        self::assertStringContainsString('<sheetFormatPr defaultRowHeight="15" outlineLevelRow="2"/>', $xml);
        self::assertStringContainsString('<row r="1">', $xml);
        self::assertStringContainsString('<row r="2" outlineLevel="1">', $xml);
        self::assertStringContainsString('<row r="3" hidden="1" outlineLevel="2">', $xml);
        self::assertStringContainsString('<row r="4" ht="20" customHeight="1" outlineLevel="1" collapsed="1">', $xml);

        $sheet = $this->loadSheet($bytes);
        self::assertSame('Till 1', self::value($sheet, 'A3'));
        self::assertSame(1, self::value($sheet, 'B5'));
        self::assertSame(2, $sheet->getRowDimension(3)->getOutlineLevel());
        self::assertFalse($sheet->getRowDimension(3)->getVisible());
        self::assertFalse($sheet->getShowSummaryBelow());
    }

    public function testTabColorAndSummaryAboveShareSheetPr(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $writer->addWorksheet('S', new SheetOptions(tabColor: '#FF0000', outlineSummaryAbove: true));
        });

        self::assertStringContainsString(
            '<sheetPr><tabColor rgb="FFFF0000"/><outlinePr summaryBelow="0"/></sheetPr>',
            $this->part($bytes, 'xl/worksheets/sheet1.xml'),
        );
    }

    public function testOutlineLevelsMustBeDeclared(): void
    {
        $writer = Writer::inMemory();
        $sheet = $writer->addWorksheet('S', new SheetOptions(rowOutlineLevels: 1));
        $sheet->beginRow(outlineLevel: 1);

        try {
            $sheet->beginRow(outlineLevel: 2);
            self::fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('rowOutlineLevels', $e->getMessage());
        }
        foreach ([8, -1] as $level) {
            try {
                $sheet->beginRow(outlineLevel: $level);
                self::fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('0..7', $e->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $writer->addWorksheet('T', new SheetOptions(rowOutlineLevels: 8));
    }

    public function testTables(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $sheet = $writer->addWorksheet('Orders');
            $sheet->writeRow(['Product', 'Units', "Line\nbreak"]);
            for ($i = 0; $i < 5; ++$i) {
                $sheet->writeRow(['Widget', $i, 2.5]);
            }
            $sheet->addTable(new Table(1, 1, $sheet->row(), 3, name: 'Orders', columns: ['Product', 'Units', "Line\nbreak"]));
            $sheet->addTable(new Table(10, 1, 12, 2, headerRow: false, style: TableStyle::none()));

            $sheet = $writer->addWorksheet('Second');
            $sheet->writeRows([['A & B'], [1]]);
            $sheet->addTable(new Table(1, 1, 2, 1, columns: ['A & B'], style: TableStyle::light(1), columnStripes: true));

            $writer->addWorksheet('Third')->addTable(new Table(1, 1, 3, 2, columns: ["T\tab", "C\rR"], style: TableStyle::dark(11), highlightFirstColumn: true, highlightLastColumn: true, autoFilter: false));
        });
        $names = $this->partNames($bytes);
        foreach ([
            'xl/tables/table1.xml',
            'xl/tables/table2.xml',
            'xl/tables/table3.xml',
            'xl/tables/table4.xml',
            'xl/worksheets/_rels/sheet1.xml.rels',
            'xl/worksheets/_rels/sheet2.xml.rels',
            'xl/worksheets/_rels/sheet3.xml.rels',
        ] as $part) {
            self::assertContains($part, $names);
        }

        $t1 = $this->part($bytes, 'xl/tables/table1.xml');
        self::assertStringContainsString('id="1" name="Orders" displayName="Orders" ref="A1:C6" totalsRowShown="0"', $t1);
        self::assertStringContainsString('<autoFilter ref="A1:C6"/>', $t1);
        self::assertStringContainsString('<tableColumn id="3" name="Line_x000A_break"/>', $t1);
        self::assertStringContainsString('name="TableStyleMedium2" showFirstColumn="0" showLastColumn="0" showRowStripes="1" showColumnStripes="0"', $t1);

        $t2 = $this->part($bytes, 'xl/tables/table2.xml');
        self::assertStringContainsString('name="Table2"', $t2);
        self::assertStringContainsString('headerRowCount="0"', $t2);
        self::assertStringNotContainsString('autoFilter', $t2);
        self::assertStringContainsString('<tableColumn id="1" name="Column1"/>', $t2);
        self::assertStringNotContainsString('TableStyle', $t2);

        $t3 = $this->part($bytes, 'xl/tables/table3.xml');
        self::assertStringContainsString('name="Table3"', $t3);
        self::assertStringContainsString('<tableColumn id="1" name="A &amp; B"/>', $t3);
        self::assertStringContainsString('name="TableStyleLight1"', $t3);
        self::assertStringContainsString('showColumnStripes="1"', $t3);

        $t4 = $this->part($bytes, 'xl/tables/table4.xml');
        self::assertStringContainsString('name="T_x0009_ab"', $t4);
        self::assertStringContainsString('name="C_x000D_R"', $t4);
        self::assertStringContainsString('name="TableStyleDark11" showFirstColumn="1" showLastColumn="1"', $t4);
        self::assertStringNotContainsString('autoFilter', $t4);

        $sheet = $this->part($bytes, 'xl/worksheets/sheet1.xml');
        self::assertStringEndsWith('<tableParts count="2"><tablePart r:id="rId1"/><tablePart r:id="rId2"/></tableParts></worksheet>', $sheet);
        self::assertStringContainsString(
            'Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/table" Target="../tables/table3.xml"',
            $this->part($bytes, 'xl/worksheets/_rels/sheet2.xml.rels'),
        );
        self::assertSame(4, \substr_count($this->part($bytes, '[Content_Types].xml'), 'spreadsheetml.table+xml'));
        foreach ($names as $name) {
            self::assertTrue((new \DOMDocument())->loadXML($this->part($bytes, $name)), $name);
        }

        $workbook = $this->load($bytes);
        self::assertSame('Product', self::value($workbook->getSheet(0), 'A1'));
        $tables = \array_map(static fn(\PhpOffice\PhpSpreadsheet\Worksheet\Table $t): string => $t->getName(), \iterator_to_array($workbook->getSheet(0)->getTableCollection()));
        self::assertSame(['Orders', 'Table2'], \array_values($tables));
    }

    public function testTableValidation(): void
    {
        $writer = Writer::inMemory();
        $sheet = $writer->addWorksheet('S');
        $t = self::table(...);
        $rejects = self::rejects(...);
        $rejects(InvalidArgumentException::class, static fn() => $sheet->addTable($t(3, 2)), 'header names are required');
        $rejects(InvalidArgumentException::class, static fn() => $sheet->addTable($t(1, 1, columns: ['a'])), 'needs a data row');
        $rejects(InvalidArgumentException::class, static fn() => $sheet->addTable($t(3, 2, columns: ['a'])), 'column count');
        $rejects(InvalidArgumentException::class, static fn() => $sheet->addTable($t(3, 2, columns: ['a', 'A'])), 'duplicate column');
        $rejects(InvalidArgumentException::class, static fn() => $sheet->addTable($t(3, 2, columns: ['a', ''])), 'empty column');
        $rejects(InvalidArgumentException::class, static fn() => $sheet->addTable($t(3, 1, columns: [\str_repeat('x', 256)])), 'long column');
        $rejects(InvalidArgumentException::class, static fn() => $sheet->addTable(new Table(0, 1, 3, 1, columns: ['a'])), 'range');
        $rejects(InvalidTableNameException::class, static fn() => $sheet->addTable($t(3, 1, columns: ['a'], name: 'A1')), 'reference');
        $rejects(InvalidTableNameException::class, static fn() => $sheet->addTable($t(3, 1, columns: ['a'], name: 'my table')), 'space');
        $rejects(InvalidArgumentException::class, static fn() => TableStyle::medium(29), 'style number');
        $rejects(InvalidArgumentException::class, static fn() => TableStyle::light(0), 'style number');

        $sheet->addTable($t(3, 1, columns: ['a'], name: 'Data'));
        $rejects(OverlappingRangeException::class, static fn() => $sheet->addTable(new Table(3, 1, 5, 1, columns: ['b'])), 'overlap');
        $rejects(DuplicateTableNameException::class, static fn() => $sheet->addTable(new Table(10, 1, 12, 1, columns: ['b'], name: 'DATA')), 'duplicate name');
        $rejects(OverlappingRangeException::class, static fn() => $sheet->mergeCells(2, 1, 2, 3), 'merge over table');
        $rejects(OverlappingRangeException::class, static fn() => $sheet->autoFilter(1, 1, 9, 1), 'filter over table');

        $sheet->mergeCells(20, 1, 20, 2);
        $rejects(OverlappingRangeException::class, static fn() => $sheet->addTable(new Table(19, 1, 21, 1, columns: ['c'])), 'table over merge');
        $sheet->autoFilter(30, 1, 40, 2);
        $rejects(OverlappingRangeException::class, static fn() => $sheet->addTable(new Table(35, 2, 36, 2, columns: ['d'])), 'table over filter');

        $sheet = $writer->addWorksheet('T');
        $rejects(DuplicateTableNameException::class, static fn() => $sheet->addTable($t(3, 1, columns: ['a'], name: 'data')), 'names are workbook-wide');
        $sheet->addTable($t(3, 1, columns: ['a']));
        $bytes = $writer->finish();

        self::assertStringContainsString('name="Table2"', $this->part($bytes, 'xl/tables/table2.xml'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function tableNames(): iterable
    {
        foreach (['Orders', '_t', 'Sales.2024', 'Résumé', 'R1C1x', 'XFE1', 'ABCD1', '\\name', 'A0', 'A1048577'] as $name) {
            yield $name => [$name, true];
        }
        foreach (['', '1abc', 'A1', 'xfd1048576', 'R', 'C', 'r1c1', 'RC', 'R12', 'C3', 'has space', 'a-b', 'a:b', \str_repeat('n', 256), "bad\xFF"] as $name) {
            yield 'invalid ' . $name => [$name, false];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tableNames')]
    public function testTableNameRules(string $name, bool $valid): void
    {
        $sheet = Writer::inMemory()->addWorksheet('S');
        if (!$valid) {
            $this->expectException(InvalidTableNameException::class);
        }
        $sheet->addTable(new Table(1, 1, 2, 1, name: $name, columns: ['a']));
        self::assertTrue($valid);
    }

    public function testAutoFitMeasuresTheSample(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $header = $writer->addStyle(Style::create()->bold());
            $big = $writer->addStyle(Style::create()->fontSize(22));
            $money = $writer->addStyle(Style::create()->numberFormat(NumberFormat::THOUSANDS2));
            $sheet = $writer->addWorksheet('Fit', new SheetOptions(
                columns: [2 => new Column(width: 30), 6 => new Column(hidden: true)],
                autoFit: true,
            ));
            $sheet->writeRow([new Styled('Name', $header), 'fixed', new Styled(1_234_567.0, $money), true, new Styled('x', $big)]);
            $sheet->writeRow(['A much longer product name', 'x', new Styled(1.0, $money), false, new Styled('x', $big)]);
            $sheet->writeRow(["short\nWith a very long second line in a wrapped cell"]);
            $sheet->skipCells(7)->write(42)->endRow();
        });
        $xml = $this->part($bytes, 'xl/worksheets/sheet1.xml');
        $widths = self::colWidths($xml);

        self::assertGreaterThanOrEqual(40.0, $widths[1]);
        self::assertLessThan(50.0, $widths[1], 'longest line of column A');
        self::assertSame(30.0, $widths[2], 'explicit widths win');
        self::assertGreaterThanOrEqual(9.0, $widths[3]);
        self::assertLessThan(13.0, $widths[3], '1,234,567.00 fits');
        self::assertGreaterThanOrEqual(5.0, $widths[4]);
        self::assertLessThan(7.0, $widths[4], 'FALSE');
        self::assertGreaterThan(2.0, $widths[5], 'larger font is wider');
        self::assertSame(9.140625, $widths[6], 'hidden column without content keeps the default width');
        self::assertStringContainsString('<col min="6" max="6" width="9.140625" hidden="1"/>', $xml);
        self::assertArrayNotHasKey(7, $widths, 'skipped empty columns get no entry');
        self::assertLessThan(3.0, $widths[8]);
        self::assertLessThan(\strpos($xml, '<sheetData>'), \strpos($xml, '<cols>'));
        self::assertSame(42, self::value($this->loadSheet($bytes), 'H4'));
    }

    public function testAutoFitMeasuresEveryKindOfValue(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $date = $writer->addStyle(Style::create()->numberFormat('dd mmmm yyyy'));
            $sheet = $writer->addWorksheet('Kinds', new SheetOptions(autoFit: new AutoFit(sampleRows: 100)));
            $sheet->writeRows([
                [new \DateTimeImmutable('2024-05-01'), new \DateTimeImmutable('2024-05-01 10:30'), new \DateInterval('PT30H'), Suit::Hearts, new Formula('SUM(A1:A9)'), new Styled(new \DateTimeImmutable('2024-05-01'), $date)],
                [new \DateTimeImmutable('1800-01-01'), \NAN, -1.5e15, 1e-12, 0.1 + 0.2, 'x'],
            ]);
            $sheet->writeRecords([['a' => 1234.5678]], [new ColumnSpec('a', numberFormat: '0.00%')]);
        });
        $widths = self::colWidths($this->part($bytes, 'xl/worksheets/sheet1.xml'));

        self::assertGreaterThan(9.0, $widths[1], '1800-01-01 is written as text and measured as such');
        self::assertGreaterThan(10.0, $widths[2], 'date and time');
        self::assertGreaterThan(6.0, $widths[3], 'duration');
        self::assertArrayHasKey(4, $widths);
        self::assertGreaterThan(9.0, $widths[5], 'formulas are not measured, 0.30000000000000004 is cut to 11 characters');
        self::assertSame(17.0, $widths[6], '31 September 2026: (117 px + 2) / 7');
    }

    public function testAutoFitStopsAfterTheSampleAndKeepsStreaming(): void
    {
        $chunks = [];
        $writer = Writer::toCallback(static function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        }, new \Vensas\Hypaxel\Options(flushThreshold: 4096));
        $sheet = $writer->addWorksheet('S', new SheetOptions(autoFit: new AutoFit(sampleRows: 10, maxWidth: 20.0)));
        for ($i = 0; $i < 9; ++$i) {
            $sheet->writeRow(['row ' . $i, $i]);
        }
        $writer->flush();
        self::assertLessThan(100, \strlen(\implode('', $chunks)), 'only the ZIP entry header is written while the sample is held back');

        $sheet->writeRow(['row 9', 9]);
        $sheet->writeRow(['a value far wider than anything in the sample, which is not measured', 1]);
        $sheet->writeRows((static function () {
            for ($i = 0; $i < 20_000; ++$i) {
                yield [$i, $i * 1.5];
            }
        })());
        $writer->finish();

        self::assertGreaterThan(3, \count($chunks), 'output is still flushed incrementally after the sample');
        $widths = self::colWidths($this->part(\implode('', $chunks), 'xl/worksheets/sheet1.xml'));
        self::assertCount(2, $widths);
        self::assertLessThan(10.0, $widths[1], 'rows after the sample are not measured');
    }

    public function testAutoFitSampleEndsInsideARowBatchAndWithCellWrites(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $sheet = $writer->addWorksheet('S', new SheetOptions(autoFit: new AutoFit(sampleRows: 2)));
            $sheet->writeRows([['ab'], ['abcd'], [\str_repeat('w', 100)]]);
            $other = $writer->addWorksheet('T', new SheetOptions(autoFit: new AutoFit(sampleRows: 1)));
            $other->write('abc')->endRow();
            $other->write(\str_repeat('w', 100))->endRow();
        });

        self::assertLessThan(6.0, self::colWidths($this->part($bytes, 'xl/worksheets/sheet1.xml'))[1]);
        self::assertLessThan(6.0, self::colWidths($this->part($bytes, 'xl/worksheets/sheet2.xml'))[1]);
        self::assertSame(\str_repeat('w', 100), self::value($this->loadSheet($bytes), 'A3'));
    }

    public function testAutoFitRespectsMaxWidthAndShortSheets(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $writer->addWorksheet('S', new SheetOptions(autoFit: new AutoFit(maxWidth: 15.0)))->writeRow([\str_repeat('x', 500)]);
            $writer->addWorksheet('Empty', new SheetOptions(autoFit: true));
        });

        self::assertSame([1 => 15.0], self::colWidths($this->part($bytes, 'xl/worksheets/sheet1.xml')));
        self::assertStringNotContainsString('<cols>', $this->part($bytes, 'xl/worksheets/sheet2.xml'));
    }

    public function testAutoFitValidation(): void
    {
        foreach ([static fn() => new AutoFit(sampleRows: 0), static fn() => new AutoFit(maxWidth: \NAN), static fn() => new AutoFit(maxWidth: 0)] as $attempt) {
            try {
                $attempt();
                self::fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testEverythingTogetherKeepsTheSchemaOrder(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $sheet = $writer->addWorksheet('All', new SheetOptions(freezeRows: 1, tabColor: '#0000FF', rowOutlineLevels: 1, autoFit: true));
            $sheet->writeRow(['Region', 'Revenue']);
            $sheet->writeRow(['North', 10]);
            $sheet->beginRow(outlineLevel: 1)->write('  Store')->write(4)->endRow();
            $sheet->addTable(new Table(1, 1, 3, 2, columns: ['Region', 'Revenue']));
        });
        $xml = $this->part($bytes, 'xl/worksheets/sheet1.xml');
        $positions = [];
        foreach (['<sheetPr>', '<sheetViews>', '<sheetFormatPr', '<cols>', '<sheetData>', '<pageMargins', '<tableParts'] as $tag) {
            $position = \strpos($xml, $tag);
            self::assertIsInt($position, $tag . ' missing');
            $positions[] = $position;
        }
        $sorted = $positions;
        \sort($sorted);

        self::assertSame($sorted, $positions);
        self::assertSame('  Store', self::value($this->loadSheet($bytes), 'A3'));
    }

    public function testWholeRowWritesFillAnExplicitlyBegunRow(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $sheet = $writer->addWorksheet('S', new SheetOptions(rowOutlineLevels: 1));
            $sheet->beginRow(outlineLevel: 1)->writeRow(['grouped', 1]);
            $sheet->writeRow(['plain', 2]);
            $sheet->beginRow(height: 30.0)->write('partial');
            $sheet->writeRow(['next row', 3]);
            $sheet->beginRow(hidden: true)->skipCells(1);
            $sheet->writeRow(['after skip']);
            $sheet->beginRow(outlineLevel: 1)->endRow();
            $sheet->writeRow(['after empty row']);
            $sheet->beginRow(height: 12.0);
            self::assertSame(0, $sheet->writeRows([]), 'nothing written: the begun row stays open');
            $sheet->writeRow(['into the row from before'], $writer->addStyle(Style::create()->bold()));
        });
        $xml = $this->part($bytes, 'xl/worksheets/sheet1.xml');

        self::assertStringContainsString('<row r="1" outlineLevel="1"><c t="inlineStr"><is><t>grouped</t>', $xml);
        self::assertStringContainsString('<row r="3" ht="30" customHeight="1"><c t="inlineStr"><is><t>partial</t></is></c></row>', $xml);
        self::assertStringContainsString('<row r="5" hidden="1"></row>', $xml);
        self::assertStringContainsString('<row r="7" outlineLevel="1"></row>', $xml);
        self::assertStringContainsString('<row r="9" ht="12" customHeight="1"><c s="1" t="inlineStr"><is><t>into the row from before</t>', $xml);
        $sheet = $this->loadSheet($bytes);
        self::assertSame('plain', self::value($sheet, 'A2'));
        self::assertSame('next row', self::value($sheet, 'A4'));
        self::assertSame('after skip', self::value($sheet, 'A6'));
        self::assertSame('after empty row', self::value($sheet, 'A8'));
        self::assertSame(9, $sheet->getHighestRow());
    }

    public function testRecordsFillAnExplicitlyBegunRow(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $sheet = $writer->addWorksheet('S');
            $sheet->writeRow(['title']);
            $sheet->beginRow(height: 25.0);
            self::assertSame(2, $sheet->writeRecords([['name' => 'a'], ['name' => 'b']], ['name']));
            self::assertSame(3, $sheet->row());
        });

        self::assertStringContainsString('<row r="2" ht="25" customHeight="1"><c t="inlineStr"><is><t>a</t>', $this->part($bytes, 'xl/worksheets/sheet1.xml'));
    }

    public function testARejectedFirstRowKeepsTheBegunRowOpen(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $sheet = $writer->addWorksheet('S');
            $sheet->beginRow(height: 18.0);
            try {
                $sheet->writeRow([[1]]);
                self::fail('Expected an exception.');
            } catch (\Vensas\Hypaxel\Exception\UnsupportedValueException) {
            }
            self::assertSame(1, $sheet->row());
            $sheet->writeRow(['kept']);
        });

        self::assertStringContainsString('<row r="1" ht="18" customHeight="1"><c t="inlineStr"><is><t>kept</t>', $this->part($bytes, 'xl/worksheets/sheet1.xml'));
    }

    /**
     * @param list<string> $columns
     */
    private static function table(int $lastRow, int $lastColumn, array $columns = [], ?string $name = null): Table
    {
        return new Table(1, 1, $lastRow, $lastColumn, name: $name, columns: $columns);
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    private static function rejects(string $exception, callable $attempt, string $why): void
    {
        try {
            $attempt();
            self::fail('Expected ' . $exception . ': ' . $why);
        } catch (\Exception $e) {
            self::assertInstanceOf($exception, $e, $why);
        }
    }

    /**
     * @return array<int, float>
     */
    private static function colWidths(string $xml): array
    {
        \preg_match_all('/<col min="(\d+)" max="\d+" width="([0-9.]+)"/', $xml, $m, \PREG_SET_ORDER);
        $widths = [];
        foreach ($m as [, $index, $width]) {
            $widths[(int) $index] = (float) $width;
        }

        return $widths;
    }
}
