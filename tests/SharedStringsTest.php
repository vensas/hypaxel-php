<?php

declare(strict_types=1);

namespace Vensas\Hypaxel\Tests;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Vensas\Hypaxel\Options;
use Vensas\Hypaxel\Style;
use Vensas\Hypaxel\Styled;
use Vensas\Hypaxel\Tests\Support\XlsxTestCase;
use Vensas\Hypaxel\Writer;

final class SharedStringsTest extends XlsxTestCase
{
    public function testStringsAreDeduplicatedAcrossWorksheets(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $bold = $writer->addStyle(Style::create()->bold());
            $first = $writer->addWorksheet('First');
            $first->writeRow(['apple', 'banana', 'apple', 42, '42', ' padded ', 'a<b>&c', "bad\xFF", '']);
            $first->writeRow([null, 'apple', new Styled('banana', $bold)]);
            $first->write('apple')->write('cherry')->endRow();
            $writer->addWorksheet('Second')->writeRow(['banana', 'date', '0042']);
        }, new Options(sharedStrings: true));
        $workbook = $this->load($bytes);
        $first = $workbook->getSheet(0);

        self::assertSame(['apple', 'banana', 'apple', 42, '42', ' padded ', 'a<b>&c', "bad\u{FFFD}", ''], self::values($first)[0]);
        self::assertSame(DataType::TYPE_STRING, $first->getCell('E1')->getDataType());
        self::assertSame([null, 'apple', 'banana'], \array_slice(self::values($first)[1], 0, 3));
        self::assertTrue($first->getStyle('C2')->getFont()->getBold());
        self::assertSame('cherry', self::value($first, 'B3'));
        self::assertSame(['banana', 'date', '0042'], self::values($workbook->getSheet(1))[0]);

        $strings = $this->part($bytes, 'xl/sharedStrings.xml');
        self::assertStringContainsString('count="15" uniqueCount="10"', $strings);
        self::assertSame(1, \substr_count($strings, '<t>apple</t>'));
        self::assertStringContainsString('<si><t>42</t></si>', $strings);
        self::assertStringContainsString('<si><t xml:space="preserve"> padded </t></si>', $strings);
        self::assertStringContainsString('<si><t>a&lt;b&gt;&amp;c</t></si>', $strings);
        self::assertStringContainsString('<c t="s"><v>0</v></c><c t="s"><v>1</v></c><c t="s"><v>0</v></c><c><v>42</v></c>', $this->part($bytes, 'xl/worksheets/sheet1.xml'));
        self::assertStringContainsString('sharedStrings+xml', $this->part($bytes, '[Content_Types].xml'));
        self::assertStringContainsString('Target="sharedStrings.xml"', $this->part($bytes, 'xl/_rels/workbook.xml.rels'));
    }

    public function testNoSharedStringsPartWithoutStrings(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $writer->addWorksheet('Numbers')->writeRow([1, 2.5, true]);
        }, new Options(sharedStrings: true));

        self::assertNotContains('xl/sharedStrings.xml', $this->partNames($bytes));
        self::assertStringNotContainsString('sharedStrings', $this->part($bytes, '[Content_Types].xml'));
        self::assertSame(1, self::value($this->loadSheet($bytes), 'A1'));
    }

    public function testALargeTableIsWrittenInChunks(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $sheet = $writer->addWorksheet('Many');
            for ($i = 0; $i < 6000; ++$i) {
                $sheet->writeRow(['Unique string number ' . $i . ' with some padding to grow the table']);
            }
        }, new Options(sharedStrings: true));
        $sheet = $this->loadSheet($bytes);

        self::assertSame('Unique string number 0 with some padding to grow the table', self::value($sheet, 'A1'));
        self::assertSame('Unique string number 5999 with some padding to grow the table', self::value($sheet, 'A6000'));
        self::assertStringContainsString('count="6000" uniqueCount="6000"', $this->part($bytes, 'xl/sharedStrings.xml'));
    }

    public function testTextLimitAppliesToSharedStrings(): void
    {
        $sheet = Writer::inMemory(new Options(sharedStrings: true))->addWorksheet('Long');

        $this->expectException(\Vensas\Hypaxel\Exception\LimitExceededException::class);
        $sheet->writeRow([\str_repeat('x', 32768)]);
    }
}
