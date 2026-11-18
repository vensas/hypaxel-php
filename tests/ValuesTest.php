<?php

declare(strict_types=1);

namespace Vensas\Hypaxel\Tests;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Vensas\Hypaxel\Exception\UnsupportedValueException;
use Vensas\Hypaxel\Formula;
use Vensas\Hypaxel\Options;
use Vensas\Hypaxel\Style;
use Vensas\Hypaxel\Styled;
use Vensas\Hypaxel\Tests\Support\Priority;
use Vensas\Hypaxel\Tests\Support\Status;
use Vensas\Hypaxel\Tests\Support\Suit;
use Vensas\Hypaxel\Tests\Support\XlsxTestCase;
use Vensas\Hypaxel\Writer;

final class ValuesTest extends XlsxTestCase
{
    public function testScalarsKeepTheirTypes(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $writer->addWorksheet('Values')->writeRow(['text', 42, -7, 1.5, 1 / 3, true, false, \PHP_INT_MAX, 3.0, 1e300, '']);
        });
        $sheet = $this->loadSheet($bytes);

        self::assertSame('text', self::value($sheet, 'A1'));
        self::assertSame(DataType::TYPE_INLINE, $sheet->getCell('A1')->getDataType());
        self::assertSame(42, self::value($sheet, 'B1'));
        self::assertSame(-7, self::value($sheet, 'C1'));
        self::assertSame(1.5, self::value($sheet, 'D1'));
        self::assertSame(1 / 3, self::value($sheet, 'E1'));
        self::assertTrue(self::value($sheet, 'F1'));
        self::assertFalse(self::value($sheet, 'G1'));
        self::assertSame(DataType::TYPE_BOOL, $sheet->getCell('G1')->getDataType());
        self::assertEquals((float) \PHP_INT_MAX, self::value($sheet, 'H1'));
        self::assertSame(3, self::value($sheet, 'I1'));
        self::assertSame(1e300, self::value($sheet, 'J1'));
        self::assertContains(self::value($sheet, 'K1'), ['', null]);
    }

    public function testNullLeavesTheCellEmptyAndLaterCellsKeepTheirColumn(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $sheet = $writer->addWorksheet('Gaps');
            $sheet->writeRow([null, 'b', null, null, 5, 'f', 1.5, true, new Formula('1+1')]);
            $sheet->writeRow(['a', 2]);
        });
        $sheet = $this->loadSheet($bytes);

        self::assertNull(self::value($sheet, 'A1'));
        self::assertSame('b', self::value($sheet, 'B1'));
        self::assertNull(self::value($sheet, 'C1'));
        self::assertSame(5, self::value($sheet, 'E1'));
        self::assertSame('f', self::value($sheet, 'F1'));
        self::assertSame(1.5, self::value($sheet, 'G1'));
        self::assertTrue(self::value($sheet, 'H1'));
        self::assertSame('=1+1', self::value($sheet, 'I1'));
        self::assertSame('a', self::value($sheet, 'A2'));
        self::assertSame(2, self::value($sheet, 'B2'));

        $xml = $this->part($bytes, 'xl/worksheets/sheet1.xml');
        self::assertStringContainsString('<row r="1"><c r="B1" t="inlineStr">', $xml);
        self::assertStringContainsString('<c r="E1"><v>5</v></c><c r="F1"', $xml);
        self::assertStringContainsString('<row r="2"><c t="inlineStr"><is><t>a</t></is></c><c><v>2</v></c></row>', $xml);
    }

    public function testNonFiniteNumbersBecomeNumErrors(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $style = $writer->addStyle(Style::create()->bold());
            $writer->addWorksheet('Errors')->writeRow([\NAN, \INF, -\INF, new Styled(\NAN, $style), null, \INF]);
        });
        $sheet = $this->loadSheet($bytes);

        foreach (['A1', 'B1', 'C1', 'D1', 'F1'] as $cell) {
            self::assertSame('#NUM!', self::value($sheet, $cell), $cell);
            self::assertSame(DataType::TYPE_ERROR, $sheet->getCell($cell)->getDataType(), $cell);
        }
    }

    public function testTextIsEscapedAndRoundTrips(): void
    {
        $texts = [
            'a&b<c>d"e\'f',
            'ünïcødé 😀 日本語 مرحبا',
            ' leading and trailing ',
            "line1\nline2\ttabbed\r",
            'literal _x0041_ stays',
            "control \x01\x1F end",
            "\u{FFFE}non\u{FFFF}characters",
            ']]> <![CDATA[ &amp;',
            '=NOT(A_FORMULA)',
            '0123',
        ];
        $bytes = $this->build(static function (Writer $writer) use ($texts): void {
            $sheet = $writer->addWorksheet('Text');
            foreach ($texts as $text) {
                $sheet->writeRow([$text, 1]);
            }
        });
        $sheet = $this->loadSheet($bytes);

        foreach ($texts as $i => $text) {
            self::assertSame($text, self::value($sheet, 'A' . ($i + 1)), \bin2hex($text));
            self::assertSame(DataType::TYPE_INLINE, $sheet->getCell('A' . ($i + 1))->getDataType());
        }
        $xml = $this->part($bytes, 'xl/worksheets/sheet1.xml');
        self::assertStringContainsString('<t>literal _x005F_x0041_ stays</t>', $xml);
        self::assertStringContainsString('<t>control _x0001__x001F_ end</t>', $xml);
        self::assertStringContainsString('<t xml:space="preserve"> leading and trailing </t>', $xml);
    }

    public function testInvalidUtf8IsReplacedInsteadOfCorruptingTheFile(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $writer->addWorksheet('Latin1')->writeRow(["caf\xE9", 'ok', "\xFF\xFE"]);
        });
        $sheet = $this->loadSheet($bytes);

        self::assertSame("caf\u{FFFD}", self::value($sheet, 'A1'));
        self::assertSame('ok', self::value($sheet, 'B1'));
        self::assertSame("\u{FFFD}\u{FFFD}", self::value($sheet, 'C1'));
    }

    public function testFormulasAreStoredWithoutTheEqualsSignAndForceRecalculation(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $writer->addWorksheet('F')->writeRow([2, 3, new Formula('=A1*B1'), new Formula('IF(A1<B1,"a&b","c")')]);
        });
        $sheet = $this->loadSheet($bytes);

        self::assertSame('=A1*B1', self::value($sheet, 'C1'));
        self::assertSame(6, $sheet->getCell('C1')->getCalculatedValue());
        self::assertSame('a&b', $sheet->getCell('D1')->getCalculatedValue());
        self::assertStringContainsString('<c><f>A1*B1</f></c>', $this->part($bytes, 'xl/worksheets/sheet1.xml'));
        self::assertStringContainsString('fullCalcOnLoad="1"', $this->part($bytes, 'xl/workbook.xml'));
    }

    public function testWorkbooksWithoutFormulasDoNotForceRecalculation(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $writer->addWorksheet('Plain')->writeRow([1]);
        });

        self::assertStringNotContainsString('fullCalcOnLoad', $this->part($bytes, 'xl/workbook.xml'));
    }

    public function testStringableAndEnumsAreWritten(): void
    {
        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return 'from <object>';
            }
        };
        $bytes = $this->build(static function (Writer $writer) use ($stringable): void {
            $writer->addWorksheet('Objects')->writeRow([$stringable, Status::Closed, Priority::High, Suit::Spades]);
        });
        $sheet = $this->loadSheet($bytes);

        self::assertSame('from <object>', self::value($sheet, 'A1'));
        self::assertSame('closed', self::value($sheet, 'B1'));
        self::assertSame(3, self::value($sheet, 'C1'));
        self::assertSame('Spades', self::value($sheet, 'D1'));
    }

    public function testArraysAndPlainObjectsAreRejected(): void
    {
        $writer = Writer::inMemory();
        $sheet = $writer->addWorksheet('Bad');

        try {
            $sheet->writeRow(['ok', ['nested']]);
            self::fail('An array in a cell must be rejected.');
        } catch (UnsupportedValueException $e) {
            self::assertStringContainsString('array', $e->getMessage());
            self::assertStringContainsString('row 1, column 2', $e->getMessage());
        }

        try {
            $sheet->write(new \stdClass());
            self::fail('A plain object in a cell must be rejected.');
        } catch (UnsupportedValueException $e) {
            self::assertStringContainsString('stdClass', $e->getMessage());
        }

        $this->expectException(UnsupportedValueException::class);
        $this->expectExceptionMessage('A row must be an array or iterable');
        /** @phpstan-ignore argument.type */
        $sheet->writeRows(['not a row']);
    }

    public function testARejectedRowLeavesNoTraceAndTheFileStaysValid(): void
    {
        $writer = Writer::inMemory();
        $sheet = $writer->addWorksheet('Recover');
        $sheet->writeRow(['first']);
        try {
            $sheet->writeRow(['broken', [1]]);
        } catch (UnsupportedValueException) {
        }
        $sheet->writeRow(['second']);
        $loaded = $this->loadSheet($writer->finish());

        self::assertSame('first', self::value($loaded, 'A1'));
        self::assertSame('second', self::value($loaded, 'A2'));
        self::assertSame(2, $loaded->getHighestRow());
    }

    public function testCellReferencesOptionWritesAReferenceOnEveryCell(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $sheet = $writer->addWorksheet('Refs');
            $sheet->writeRow(['a', 1, 2.5, true, null, new Formula('A1')]);
            $sheet->write('x')->write(2)->endRow();
        }, new Options(cellReferences: true));
        $xml = $this->part($bytes, 'xl/worksheets/sheet1.xml');

        self::assertSame(0, \preg_match('/<c[ >](?!r=)/', $xml));
        self::assertStringContainsString('<c r="A1" t="inlineStr">', $xml);
        self::assertStringContainsString('<c r="B1"><v>1</v></c><c r="C1"><v>2.5</v></c><c r="D1" t="b">', $xml);
        self::assertStringContainsString('<c r="F1"><f>A1</f></c>', $xml);
        self::assertStringContainsString('<row r="2"><c r="A2" t="inlineStr"><is><t>x</t></is></c><c r="B2"><v>2</v></c></row>', $xml);
        self::assertSame(2.5, $this->loadSheet($bytes)->getCell('C1')->getValue());
    }

    public function testRowsComeFromAnyIterable(): void
    {
        $generator = (static function (): \Generator {
            yield [1, 'one'];
            yield new \ArrayIterator([2, 'two']);
            yield (static fn(): \Generator => yield from [3, 'three'])();
        })();
        $writer = Writer::inMemory();
        $count = $writer->addWorksheet('Iter')->writeRows($generator);
        $sheet = $this->loadSheet($writer->finish());

        self::assertSame(3, $count);
        self::assertSame([[1, 'one'], [2, 'two'], [3, 'three']], self::values($sheet));
    }

    public function testCellByCellWriting(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $bold = $writer->addStyle(Style::create()->bold());
            $sheet = $writer->addWorksheet('Cells');
            self::assertSame(0, $sheet->row());
            $sheet->write('a')->write(1, $bold)->skipCells(2)->write(2.5);
            self::assertSame(1, $sheet->row());
            self::assertSame(5, $sheet->column());
            $sheet->endRow();
            self::assertSame(0, $sheet->column());
            $sheet->endRow();
            $sheet->write(null)->write('b2')->endRow();
            $sheet->skipRows(2);
            $sheet->writeRow(['after']);
            $sheet->beginRowAt(10)->write('ten');
            $sheet->writeRow(['eleven']);
        });
        $sheet = $this->loadSheet($bytes);

        self::assertSame('a', self::value($sheet, 'A1'));
        self::assertSame(1, self::value($sheet, 'B1'));
        self::assertTrue($sheet->getStyle('B1')->getFont()->getBold());
        self::assertSame(2.5, self::value($sheet, 'E1'));
        self::assertNull(self::value($sheet, 'A2'));
        self::assertSame('b2', self::value($sheet, 'B3'));
        self::assertSame('after', self::value($sheet, 'A6'));
        self::assertSame('ten', self::value($sheet, 'A10'));
        self::assertSame('eleven', self::value($sheet, 'A11'));
    }
}
