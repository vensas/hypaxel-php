<?php

declare(strict_types=1);

namespace Vensas\Hypaxel\Tests;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet as LoadedSheet;
use Vensas\Hypaxel\Exception\DuplicateSheetNameException;
use Vensas\Hypaxel\Exception\InvalidArgumentException;
use Vensas\Hypaxel\Exception\InvalidSheetNameException;
use Vensas\Hypaxel\Exception\LimitExceededException;
use Vensas\Hypaxel\Exception\WriterStateException;
use Vensas\Hypaxel\SheetOptions;
use Vensas\Hypaxel\Tests\Support\XlsxTestCase;
use Vensas\Hypaxel\Worksheet;
use Vensas\Hypaxel\Writer;

final class LayoutTest extends XlsxTestCase
{
    public function testMultipleWorksheetsWithTheirSettings(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $writer->addWorksheet('Hidden first', new SheetOptions(hidden: true))->writeRow(['secret']);
            $writer->addWorksheet('Frozen', new SheetOptions(freezeRows: 1, freezeColumns: 2, tabColor: '#FF0000'))->writeRow(['a', 'b', 'c']);
            $writer->addWorksheet('Rows only', new SheetOptions(freezeRows: 3, showGridLines: false))->writeRow([1]);
            $writer->addWorksheet("Columns' & <only>", new SheetOptions(freezeColumns: 1))->writeRow([2]);
            self::assertSame(4, $writer->worksheetCount());
        });
        $workbook = $this->load($bytes);

        self::assertSame(['Hidden first', 'Frozen', 'Rows only', "Columns' & <only>"], $workbook->getSheetNames());
        self::assertSame(LoadedSheet::SHEETSTATE_HIDDEN, $workbook->getSheet(0)->getSheetState());
        self::assertSame(LoadedSheet::SHEETSTATE_VISIBLE, $workbook->getSheet(1)->getSheetState());
        self::assertSame(1, $workbook->getActiveSheetIndex());
        self::assertSame('secret', self::value($workbook->getSheet(0), 'A1'));

        self::assertSame('C2', $workbook->getSheet(1)->getFreezePane());
        self::assertSame('FFFF0000', $workbook->getSheet(1)->getTabColor()->getARGB());
        self::assertTrue($workbook->getSheet(1)->getShowGridlines());
        self::assertSame('A4', $workbook->getSheet(2)->getFreezePane());
        self::assertFalse($workbook->getSheet(2)->getShowGridlines());
        self::assertSame('B1', $workbook->getSheet(3)->getFreezePane());
        self::assertNull($workbook->getSheet(0)->getFreezePane());
        self::assertSame(2, self::value($workbook->getSheet(3), 'A1'));

        self::assertStringContainsString('<workbookView activeTab="1"/>', $this->part($bytes, 'xl/workbook.xml'));
        self::assertStringContainsString('tabSelected="1"', $this->part($bytes, 'xl/worksheets/sheet1.xml'));
        self::assertStringNotContainsString('tabSelected', $this->part($bytes, 'xl/worksheets/sheet2.xml'));
    }

    public function testMergedCellsAndAutoFilter(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $sheet = $writer->addWorksheet("O'Brien & Co");
            $sheet->writeRow(['Title']);
            $sheet->writeRow(['id', 'name']);
            $sheet->writeRow([1, 'Ada']);
            $sheet->mergeCells(1, 1, 1, 4)->mergeCells(5, 2, 8, 3)->autoFilter(2, 1, 3, 2);
            $writer->addWorksheet('Second')->autoFilter(1, 1, 1, 3);
        });
        $workbook = $this->load($bytes);
        $sheet = $workbook->getSheet(0);

        self::assertSame(['A1:D1', 'B5:C8'], \array_values($sheet->getMergeCells()));
        self::assertSame('A2:B3', $sheet->getAutoFilter()->getRange());
        self::assertSame('A1:C1', $workbook->getSheet(1)->getAutoFilter()->getRange());
        $xml = $this->part($bytes, 'xl/workbook.xml');
        self::assertStringContainsString('<definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'O\'\'Brien &amp; Co\'!$A$2:$B$3</definedName>', $xml);
        self::assertStringContainsString('localSheetId="1" hidden="1">\'Second\'!$A$1:$C$1</definedName>', $xml);
    }

    public function testInvalidRangesAreRejected(): void
    {
        $sheet = Writer::inMemory()->addWorksheet('Ranges');

        foreach ([
            static fn() => $sheet->mergeCells(1, 1, 1, 1),
            static fn() => $sheet->mergeCells(0, 1, 2, 2),
            static fn() => $sheet->mergeCells(2, 1, 1, 2),
            static fn() => $sheet->mergeCells(1, 3, 2, 2),
            static fn() => $sheet->mergeCells(1, 1, Worksheet::MAX_ROWS + 1, 2),
            static fn() => $sheet->autoFilter(1, 1, 1, Worksheet::MAX_COLUMNS + 1),
            static fn() => $sheet->autoFilter(1, 0, 1, 1),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testAnEmptyWorkbookGetsADefaultWorksheet(): void
    {
        $workbook = $this->load(Writer::inMemory()->finish());

        self::assertSame(['Sheet1'], $workbook->getSheetNames());
    }

    public function testEveryPartIsWellFormedXml(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $sheet = $writer->addWorksheet('A & "B" <C>', new SheetOptions(freezeRows: 1, columnWidths: [10], tabColor: '#00FF00'));
            $sheet->writeRow(["a\x00b", '<&>', 1.5, true, null, new \DateTimeImmutable('2024-01-01 10:00')]);
            $sheet->mergeCells(2, 1, 2, 2)->autoFilter(1, 1, 1, 6);
            $writer->addWorksheet('Empty', new SheetOptions(hidden: true));
        });
        $names = $this->partNames($bytes);

        self::assertSame([
            'xl/worksheets/sheet1.xml',
            'xl/worksheets/sheet2.xml',
            'xl/styles.xml',
            'xl/workbook.xml',
            'xl/_rels/workbook.xml.rels',
            '[Content_Types].xml',
            '_rels/.rels',
        ], $names);
        foreach ($names as $name) {
            $document = new \DOMDocument();
            self::assertTrue($document->loadXML($this->part($bytes, $name)), $name);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSheetNames(): iterable
    {
        yield 'empty' => [''];
        yield 'too long' => [\str_repeat('a', 32)];
        yield 'too long in utf-16 units' => [\str_repeat('😀', 16)];
        yield 'colon' => ['a:b'];
        yield 'backslash' => ['a\\b'];
        yield 'slash' => ['a/b'];
        yield 'question mark' => ['a?b'];
        yield 'asterisk' => ['a*b'];
        yield 'brackets' => ['a[b]'];
        yield 'leading apostrophe' => ["'a"];
        yield 'trailing apostrophe' => ["a'"];
        yield 'reserved' => ['history'];
        yield 'invalid utf-8' => ["a\xFFb"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSheetNames')]
    public function testInvalidSheetNamesAreRejected(string $name): void
    {
        $this->expectException(InvalidSheetNameException::class);
        Writer::inMemory()->addWorksheet($name);
    }

    public function testLongestValidSheetNamesAreAccepted(): void
    {
        $names = [\str_repeat('ä', 31), \str_repeat('😀', 15) . 'x', "it's fine"];
        $bytes = $this->build(static function (Writer $writer) use ($names): void {
            foreach ($names as $name) {
                $writer->addWorksheet($name);
            }
        });

        self::assertSame($names, $this->load($bytes)->getSheetNames());
    }

    public function testDuplicateSheetNamesAreRejectedIgnoringCase(): void
    {
        $writer = Writer::inMemory();
        $writer->addWorksheet('Übersicht');
        $writer->addWorksheet('Data');

        try {
            $writer->addWorksheet('DATA');
            self::fail('Expected a DuplicateSheetNameException.');
        } catch (DuplicateSheetNameException $e) {
            self::assertStringContainsString('DATA', $e->getMessage());
        }

        $this->expectException(DuplicateSheetNameException::class);
        $writer->addWorksheet('übersicht');
    }

    public function testAFailedWorksheetLeavesTheWriterUsable(): void
    {
        $writer = Writer::inMemory();
        $first = $writer->addWorksheet('First');
        try {
            $writer->addWorksheet('bad/name');
        } catch (InvalidSheetNameException) {
        }
        try {
            $writer->addWorksheet('Frozen', new SheetOptions(freezeRows: -1));
        } catch (InvalidArgumentException) {
        }
        $first->writeRow(['still open']);
        self::assertSame($first, $writer->currentWorksheet());
        $workbook = $this->load($writer->finish());

        self::assertSame(['First'], $workbook->getSheetNames());
        self::assertSame('still open', self::value($workbook->getSheet(0), 'A1'));
    }

    public function testInvalidSheetOptionsAreRejected(): void
    {
        foreach ([
            new SheetOptions(freezeRows: Worksheet::MAX_ROWS),
            new SheetOptions(freezeColumns: Worksheet::MAX_COLUMNS),
            new SheetOptions(freezeColumns: -1),
            /** @phpstan-ignore argument.type */
            new SheetOptions(columns: [0 => new \Vensas\Hypaxel\Column(width: 1.0)]),
            /** @phpstan-ignore argument.type */
            new SheetOptions(columns: [16385 => new \Vensas\Hypaxel\Column(width: 1.0)]),
            /** @phpstan-ignore argument.type */
            new SheetOptions(columns: [1 => 12.5]),
            new SheetOptions(columnWidths: \array_fill(0, 16385, 1.0)),
        ] as $options) {
            try {
                Writer::inMemory()->addWorksheet('S', $options);
                self::fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testACompletedWorksheetCannotBeWritten(): void
    {
        $writer = Writer::inMemory();
        $first = $writer->addWorksheet('First');
        $writer->addWorksheet('Second');

        foreach ([
            static fn() => $first->writeRow([1]),
            static fn() => $first->write(1),
            static fn() => $first->beginRow(),
            static fn() => $first->endRow(),
            static fn() => $first->skipCells(),
            static fn() => $first->skipRows(),
            static fn() => $first->mergeCells(1, 1, 2, 2),
            static fn() => $first->autoFilter(1, 1, 2, 2),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Expected a WriterStateException.');
            } catch (WriterStateException $e) {
                self::assertStringContainsString('"First" is complete', $e->getMessage());
            }
        }
    }

    public function testAFinishedWriterCannotBeUsed(): void
    {
        $writer = Writer::inMemory();
        $sheet = $writer->addWorksheet('Only');
        self::assertFalse($writer->isFinished());
        $writer->finish();

        self::assertTrue($writer->isFinished());
        self::assertNull($writer->currentWorksheet());
        foreach ([
            static fn() => $writer->finish(),
            static fn() => $writer->addWorksheet('More'),
            static fn() => $sheet->writeRow([1]),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Expected a WriterStateException.');
            } catch (WriterStateException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testAWorkbookNeedsAVisibleWorksheet(): void
    {
        $writer = Writer::inMemory();
        $writer->addWorksheet('Hidden', new SheetOptions(hidden: true));

        try {
            $writer->finish();
            self::fail('Expected a WriterStateException.');
        } catch (WriterStateException $e) {
            self::assertStringContainsString('visible', $e->getMessage());
        }

        $writer->addWorksheet('Visible');
        self::assertSame(['Hidden', 'Visible'], $this->load($writer->finish())->getSheetNames());
    }

    public function testRowLimit(): void
    {
        $writer = Writer::inMemory();
        $sheet = $writer->addWorksheet('Rows');
        $sheet->skipRows(Worksheet::MAX_ROWS - 1);
        $sheet->writeRow(['last']);
        self::assertSame(Worksheet::MAX_ROWS, $sheet->row());

        foreach ([
            static fn() => $sheet->writeRow(['one too many']),
            static fn() => $sheet->write('one too many'),
            static fn() => $sheet->skipCells(),
            static fn() => $sheet->beginRow(),
            static fn() => $sheet->beginRowAt(Worksheet::MAX_ROWS + 1),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Expected a LimitExceededException.');
            } catch (LimitExceededException $e) {
                self::assertStringContainsString('1048577 is outside 1..1048576', $e->getMessage());
            }
        }
        self::assertSame(Worksheet::MAX_ROWS, $sheet->skipRows(5)->endRow()->row());
        self::assertStringContainsString('<row r="1048576"><c r="A1048576" t="inlineStr">', $this->part($writer->finish(), 'xl/worksheets/sheet1.xml'));
    }

    public function testRowsMustAscend(): void
    {
        $sheet = Writer::inMemory()->addWorksheet('Rows');
        $sheet->beginRowAt(5)->write('five');

        foreach ([5, 4, 0, -1] as $row) {
            try {
                $sheet->beginRowAt($row);
                self::fail('Expected an exception.');
            } catch (InvalidArgumentException|LimitExceededException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        foreach ([-1.0, 409.5, \NAN] as $height) {
            try {
                $sheet->beginRow($height);
                self::fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('height', $e->getMessage());
            }
        }
        foreach ([static fn() => $sheet->skipRows(-1), static fn() => $sheet->skipCells(-1)] as $attempt) {
            try {
                $attempt();
                self::fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('negative', $e->getMessage());
            }
        }
    }

    public function testColumnLimit(): void
    {
        $writer = Writer::inMemory();
        $bold = $writer->addStyle(\Vensas\Hypaxel\Style::create()->bold());
        $sheet = $writer->addWorksheet('Columns');
        $sheet->writeRow(\array_fill(0, Worksheet::MAX_COLUMNS, 1));
        $sheet->skipCells(Worksheet::MAX_COLUMNS - 1)->write('last')->endRow();

        foreach ([
            static fn() => $sheet->writeRow(\array_fill(0, Worksheet::MAX_COLUMNS + 1, 1)),
            static fn() => $sheet->writeRow(\array_fill(0, Worksheet::MAX_COLUMNS + 1, 1), $bold),
            static fn() => $sheet->skipCells(Worksheet::MAX_COLUMNS)->write('x'),
            static fn() => $sheet->skipCells(Worksheet::MAX_COLUMNS + 1),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Expected a LimitExceededException.');
            } catch (LimitExceededException $e) {
                self::assertStringContainsString('exceeds 16384 columns', $e->getMessage());
            }
        }
        $loaded = $this->loadSheet($writer->finish());
        self::assertSame(1, self::value($loaded, 'XFD1'));
        self::assertSame('last', self::value($loaded, 'XFD2'));
    }

    public function testTextLengthLimitCountsUtf16Units(): void
    {
        $writer = Writer::inMemory();
        $sheet = $writer->addWorksheet('Text');
        $longest = \str_repeat('a', Worksheet::MAX_CELL_CHARACTERS);
        $longestMultibyte = \str_repeat('ä', Worksheet::MAX_CELL_CHARACTERS);
        $sheet->writeRow([$longest, $longestMultibyte]);
        $sheet->write($longestMultibyte)->endRow();

        foreach ([
            static fn() => $sheet->writeRow(['ok', $longest . 'a']),
            static fn() => $sheet->writeRow([\str_repeat('😀', 16384)]),
            static fn() => $sheet->write($longestMultibyte . 'ä'),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Expected a LimitExceededException.');
            } catch (LimitExceededException $e) {
                self::assertStringContainsString('32768 characters; the limit is 32767', $e->getMessage());
            }
        }
        $loaded = $this->loadSheet($writer->finish());
        self::assertSame($longest, self::value($loaded, 'A1'));
        self::assertSame($longestMultibyte, self::value($loaded, 'B1'));
    }
}
