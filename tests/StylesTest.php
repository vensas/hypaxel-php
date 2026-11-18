<?php

declare(strict_types=1);

namespace Vensas\Hypaxel\Tests;

use PhpOffice\PhpSpreadsheet\Style\Fill;
use PHPUnit\Framework\Attributes\DataProvider;
use Vensas\Hypaxel\Alignment;
use Vensas\Hypaxel\Border;
use Vensas\Hypaxel\BorderLine;
use Vensas\Hypaxel\BorderStyle;
use Vensas\Hypaxel\Color;
use Vensas\Hypaxel\Column;
use Vensas\Hypaxel\Exception\InvalidArgumentException;
use Vensas\Hypaxel\Font;
use Vensas\Hypaxel\HorizontalAlignment;
use Vensas\Hypaxel\NumberFormat;
use Vensas\Hypaxel\SheetOptions;
use Vensas\Hypaxel\Style;
use Vensas\Hypaxel\Styled;
use Vensas\Hypaxel\StyleId;
use Vensas\Hypaxel\Tests\Support\XlsxTestCase;
use Vensas\Hypaxel\VerticalAlignment;
use Vensas\Hypaxel\Writer;

final class StylesTest extends XlsxTestCase
{
    public function testFontFillAndAlignmentAreReadBack(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $style = $writer->addStyle(
                Style::create()
                    ->fontName('Arial')->fontSize(14.5)->bold()->italic()->underline()->strikethrough()->fontColor('#C00000')
                    ->fill(Color::rgb(0x30, 0x54, 0x96))
                    ->align(HorizontalAlignment::Center)->valign(VerticalAlignment::Top)->wrap()->rotation(45),
            );
            $shrink = $writer->addStyle(Style::create()->shrinkToFit()->align(HorizontalAlignment::Right)->indent(2));
            $writer->addWorksheet('Styles')->writeRow([new Styled('styled', $style), 'plain', new Styled('fit', $shrink)]);
        });
        $sheet = $this->loadSheet($bytes);
        $font = $sheet->getStyle('A1')->getFont();
        $alignment = $sheet->getStyle('A1')->getAlignment();

        self::assertSame('Arial', $font->getName());
        self::assertSame(14.5, $font->getSize());
        self::assertTrue($font->getBold());
        self::assertTrue($font->getItalic());
        self::assertSame('single', $font->getUnderline());
        self::assertTrue($font->getStrikethrough());
        self::assertSame('FFC00000', $font->getColor()->getARGB());
        self::assertSame(Fill::FILL_SOLID, $sheet->getStyle('A1')->getFill()->getFillType());
        self::assertSame('FF305496', $sheet->getStyle('A1')->getFill()->getStartColor()->getARGB());
        self::assertSame('center', $alignment->getHorizontal());
        self::assertSame('top', $alignment->getVertical());
        self::assertTrue($alignment->getWrapText());
        self::assertSame(45, $alignment->getTextRotation());

        self::assertFalse($sheet->getStyle('B1')->getFont()->getBold());
        self::assertSame('Calibri', $sheet->getStyle('B1')->getFont()->getName());
        self::assertSame(Fill::FILL_NONE, $sheet->getStyle('B1')->getFill()->getFillType());
        self::assertTrue($sheet->getStyle('C1')->getAlignment()->getShrinkToFit());
        self::assertSame('right', $sheet->getStyle('C1')->getAlignment()->getHorizontal());
        self::assertSame(2, $sheet->getStyle('C1')->getAlignment()->getIndent());
    }

    /**
     * @return iterable<string, array{BorderStyle}>
     */
    public static function borderStyles(): iterable
    {
        foreach (BorderStyle::cases() as $style) {
            if ($style !== BorderStyle::None) {
                yield $style->name => [$style];
            }
        }
    }

    #[DataProvider('borderStyles')]
    public function testEveryBorderLineStyle(BorderStyle $style): void
    {
        $bytes = $this->build(static function (Writer $writer) use ($style): void {
            $id = $writer->addStyle(Style::create()->border($style, '#FF0000'));
            $writer->addWorksheet('Borders')->writeRow([new Styled('x', $id)]);
        });
        $borders = $this->loadSheet($bytes)->getStyle('A1')->getBorders();

        foreach ([$borders->getLeft(), $borders->getRight(), $borders->getTop(), $borders->getBottom()] as $edge) {
            self::assertSame($style->value, $edge->getBorderStyle());
            self::assertSame('FFFF0000', $edge->getColor()->getARGB());
        }
    }

    public function testBordersCanDifferPerEdge(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $id = $writer->addStyle(new Style(border: new Border(
                left: BorderStyle::Thin,
                bottom: new BorderLine(BorderStyle::Double, Color::hex('FF00FF00')),
            )));
            $writer->addWorksheet('Borders')->writeRow([new Styled(null, $id)]);
        });
        $borders = $this->loadSheet($bytes)->getStyle('A1')->getBorders();

        self::assertSame('thin', $borders->getLeft()->getBorderStyle());
        self::assertSame('none', $borders->getRight()->getBorderStyle());
        self::assertSame('none', $borders->getTop()->getBorderStyle());
        self::assertSame('double', $borders->getBottom()->getBorderStyle());
        self::assertSame('FF00FF00', $borders->getBottom()->getColor()->getARGB());
        self::assertStringContainsString('<c s="1"/>', $this->part($bytes, 'xl/worksheets/sheet1.xml'));
    }

    public function testNumberFormats(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $money = $writer->addStyle(Style::create()->numberFormat(NumberFormat::THOUSANDS2));
            $custom = $writer->addStyle(Style::create()->numberFormat('0.000 "kg"'));
            $percent = $writer->addStyle(new Style(numberFormat: NumberFormat::PERCENT2));
            $text = $writer->addStyle(new Style(numberFormat: NumberFormat::TEXT));
            $writer->addWorksheet('Formats')->writeRow([
                new Styled(1234.5, $money),
                new Styled(2.5, $custom),
                new Styled(0.125, $percent),
                new Styled('007', $text),
            ]);
        });
        $sheet = $this->loadSheet($bytes);

        self::assertSame('#,##0.00', $sheet->getStyle('A1')->getNumberFormat()->getFormatCode());
        self::assertSame('1,234.50', $sheet->getCell('A1')->getFormattedValue());
        self::assertSame('0.000 "kg"', $sheet->getStyle('B1')->getNumberFormat()->getFormatCode());
        self::assertSame('0.00%', $sheet->getStyle('C1')->getNumberFormat()->getFormatCode());
        self::assertSame('@', $sheet->getStyle('D1')->getNumberFormat()->getFormatCode());
        $styles = $this->part($bytes, 'xl/styles.xml');
        self::assertStringContainsString('<numFmts count="1"><numFmt numFmtId="164" formatCode="0.000 &quot;kg&quot;"/></numFmts>', $styles);
        self::assertStringContainsString('<xf numFmtId="4" ', $styles);
        self::assertStringContainsString('<xf numFmtId="10" ', $styles);
        self::assertStringContainsString('<xf numFmtId="49" ', $styles);
    }

    public function testIdenticalStylesAreDeduplicated(): void
    {
        $writer = Writer::inMemory();
        $a = $writer->addStyle(Style::create()->bold()->fill('#FF0000')->border(BorderStyle::Thin)->numberFormat('0.0'));
        $b = $writer->addStyle(new Style(
            numberFormat: '0.0',
            font: new Font(bold: true),
            fill: Color::rgb(255, 0, 0),
            border: Border::all(),
        ));
        $c = $writer->addStyle(Style::create()->bold()->fill('#00FF00')->border(BorderStyle::Thin)->numberFormat('0.0'));
        $default = $writer->addStyle(new Style());
        $writer->addWorksheet('S')->writeRow([new Styled(1, $a), new Styled(2, $c)]);
        $styles = $this->part($writer->finish(), 'xl/styles.xml');

        self::assertSame($a->index, $b->index);
        self::assertNotSame($a->index, $c->index);
        self::assertSame(0, $default->index);
        self::assertStringContainsString('<fonts count="2">', $styles);
        self::assertStringContainsString('<fills count="4">', $styles);
        self::assertStringContainsString('<borders count="2">', $styles);
        self::assertStringContainsString('<numFmts count="1">', $styles);
        self::assertStringContainsString('<cellXfs count="3">', $styles);
    }

    public function testRowStyleAppliesToCellsWithoutTheirOwn(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $bold = $writer->addStyle(Style::create()->bold());
            $italic = $writer->addStyle(Style::create()->italic());
            $writer->addWorksheet('Rows')->writeRow(['a', new Styled('b', $italic), null, 4], $bold);
        });
        $sheet = $this->loadSheet($bytes);

        self::assertTrue($sheet->getStyle('A1')->getFont()->getBold());
        self::assertFalse($sheet->getStyle('B1')->getFont()->getBold());
        self::assertTrue($sheet->getStyle('B1')->getFont()->getItalic());
        self::assertTrue($sheet->getStyle('C1')->getFont()->getBold());
        self::assertNull(self::value($sheet, 'C1'));
        self::assertTrue($sheet->getStyle('D1')->getFont()->getBold());
        self::assertSame(4, self::value($sheet, 'D1'));
    }

    public function testRowHeightRowFormatAndColumns(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $fill = $writer->addStyle(Style::create()->fill('#FFFF00'));
            $sheet = $writer->addWorksheet('Layout', new SheetOptions(
                columnWidths: [20, null, 12.5],
                columns: [2 => new Column(style: $fill), 5 => new Column(width: 30.0, hidden: true), 4 => new Column(width: 400.0)],
            ));
            $sheet->beginRow(30.0, $fill)->write('tall')->endRow();
            $sheet->beginRow(height: 12.5)->write('short');
        });
        $sheet = $this->loadSheet($bytes);
        $xml = $this->part($bytes, 'xl/worksheets/sheet1.xml');

        self::assertSame(30.0, $sheet->getRowDimension(1)->getRowHeight());
        self::assertSame(12.5, $sheet->getRowDimension(2)->getRowHeight());
        self::assertSame(20.0, $sheet->getColumnDimension('A')->getWidth());
        self::assertSame(12.5, $sheet->getColumnDimension('C')->getWidth());
        self::assertSame(255.0, $sheet->getColumnDimension('D')->getWidth());
        self::assertSame(30.0, $sheet->getColumnDimension('E')->getWidth());
        self::assertFalse($sheet->getColumnDimension('E')->getVisible());
        self::assertTrue($sheet->getColumnDimension('A')->getVisible());
        self::assertStringContainsString('<row r="1" ht="30" customHeight="1" s="1" customFormat="1">', $xml);
        self::assertStringContainsString('<col min="2" max="2" width="9.140625" style="1"/>', $xml);
        self::assertStringContainsString('<col min="1" max="1" width="20" customWidth="1"/>', $xml);
    }

    public function testStyleIdsOfAnotherWriterAreRejected(): void
    {
        $other = Writer::inMemory();
        $other->addStyle(Style::create()->bold());
        $foreign = $other->addStyle(Style::create()->italic());
        $sheet = Writer::inMemory()->addWorksheet('S');

        foreach ([
            static fn() => $sheet->writeRow([1], $foreign),
            static fn() => $sheet->writeRow([new Styled(1, $foreign)]),
            static fn() => $sheet->write(1, $foreign),
            static fn() => $sheet->beginRow(style: $foreign),
            static fn() => $sheet->writeRow([1], new StyleId(-1)),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('A foreign style id must be rejected.');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('does not belong to this writer', $e->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);
        Writer::inMemory()->addWorksheet('T', new SheetOptions(columns: [1 => new Column(style: $foreign)]));
    }

    public function testInvalidStyleValuesAreRejected(): void
    {
        foreach ([
            static fn() => Color::hex('#12345'),
            static fn() => Color::hex('nothex'),
            static fn() => Color::rgb(256, 0, 0),
            static fn() => new Font(size: 0.5),
            static fn() => new Font(size: \NAN),
            static fn() => new Font(name: ''),
            static fn() => new Alignment(indent: 256),
            static fn() => new Alignment(rotation: 200),
            static fn() => Style::create()->fill('red'),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        self::assertSame(255, (new Alignment(rotation: Alignment::VERTICAL_TEXT))->rotation);
    }

    public function testColorsAndFluentStylesAreImmutableValues(): void
    {
        self::assertSame('FF305496', Color::hex('#305496')->toHex());
        self::assertSame('80FFFFFF', Color::hex('80ffffff')->toHex());
        self::assertSame('7F010203', Color::rgb(1, 2, 3, 127)->toHex());
        self::assertSame(Color::hex(Color::LIGHT_GRAY)->argb, Color::rgb(217, 217, 217)->argb);

        $base = Style::create();
        $bold = $base->bold();
        self::assertNull($base->font);
        self::assertTrue($bold->font?->bold);
        self::assertFalse($bold->bold(false)->font?->bold);
        self::assertNull($bold->font(null)->font);
        self::assertNull($bold->fill('#000000')->fill(null)->fill);
        self::assertNull($bold->border(BorderStyle::Thin)->border(null)->border);
        self::assertNull($bold->wrap()->alignment(null)->alignment);
        self::assertNull($bold->numberFormat('0')->numberFormat(null)->numberFormat);
        self::assertSame(11.0, $bold->italic()->font?->size);
    }
}
