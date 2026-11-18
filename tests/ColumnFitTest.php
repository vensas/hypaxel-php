<?php

declare(strict_types=1);

namespace Vensas\Hypaxel\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vensas\Hypaxel\AutoFit;
use Vensas\Hypaxel\Internal\ColumnFit;
use Vensas\Hypaxel\Internal\StyleRegistry;
use Vensas\Hypaxel\Style;

final class ColumnFitTest extends TestCase
{
    public function testTextWidths(): void
    {
        self::assertSame(0.0, ColumnFit::textPx(''));
        self::assertSame(70.0, ColumnFit::textPx('0123456789'));
        self::assertSame(ColumnFit::textPx('longer line'), ColumnFit::textPx("ab\nlonger line"));
        self::assertSame(ColumnFit::textPx('b'), ColumnFit::textPx("a\r\nb"));
        self::assertGreaterThan(ColumnFit::textPx('iii'), ColumnFit::textPx('WWW'));
        self::assertSame(30.0, ColumnFit::textPx('日本'));
        self::assertSame(16.0 + 15.0, ColumnFit::textPx("é\u{1F600}\x01"));
    }

    /**
     * @return iterable<string, array{string, float|int, string}>
     */
    public static function numberShapes(): iterable
    {
        yield 'thousands' => ['#,##0.00', 1234567.891, '1,234,567.89'];
        yield 'percent' => ['0.0%', 0.256, '25.6%'];
        yield 'negative' => ['0', -42, '-42'];
        yield 'zero padded' => ['000', 7, '007'];
        yield 'quoted literal' => ['"$"#,##0', 1500, '$1,500'];
        yield 'currency' => ['[$€-407] #,##0.00', 10, '€ 10.00'];
        yield 'escaped literal and padding' => ['\\€0_)', 5, '€5)'];
        yield 'repeat fill' => ['*-0', 5, '5'];
        yield 'iso date' => ['yyyy-mm-dd', 45000, '2026-12-31'];
        yield 'long month' => ['dd mmmm yyyy', 45000, '31 September 2026'];
        yield 'short names' => ['ddd mmm yy', 45000, 'Wed Sep 26'];
        yield 'long day' => ['dddd', 45000, 'Wednesday'];
        yield 'am/pm' => ['h:mm AM/PM', 0.5, '12:00 PM'];
        yield 'elapsed hours' => ['[h]:mm:ss', 1.5, '100:00:00'];
        yield 'scientific' => ['0.00E+00', 12345, '1.23E+04'];
        yield 'general code' => ['General', 3.25, '3.25'];
        yield 'text' => ['@', 1 / 3, '0.333333333'];
        yield 'second section ignored' => ['0;(0)', 12, '12'];
        yield 'built-in thousands' => ['#,##0', 1234, '1,234'];
        yield 'built-in percent' => ['0.00%', 0.5, '50.00%'];
        yield 'built-in scientific' => ['0.00E+00', 1, '1.00E+00'];
    }

    #[DataProvider('numberShapes')]
    public function testNumberShapes(string $code, float|int $value, string $rendered): void
    {
        $styles = new StyleRegistry();
        $style = $styles->add(Style::create()->numberFormat($code));

        $number = new ColumnFit($styles, new AutoFit(), [], 0);
        $number->number($style, 1, $value);
        $text = new ColumnFit($styles, new AutoFit(), [], 0);
        $text->text(0, 1, $rendered);

        self::assertSame($text->cols(), $number->cols());
    }

    /**
     * @return iterable<string, array{float|int, string}>
     */
    public static function generalNumbers(): iterable
    {
        yield 'integer' => [42, '42'];
        yield 'large' => [1e15, '1.23457E+11'];
        yield 'negative large' => [-1e15, '-1.23457E+11'];
        yield 'tiny' => [1e-12, '1.23457E+11'];
        yield 'zero' => [0, '0'];
        yield 'not a number' => [\NAN, '#NUM!'];
        yield 'long fraction' => [0.1 + 0.2, '0.300000000'];
    }

    #[DataProvider('generalNumbers')]
    public function testGeneralNumbers(float|int $value, string $rendered): void
    {
        $styles = new StyleRegistry();
        $number = new ColumnFit($styles, new AutoFit(), [], 0);
        $number->number(0, 1, $value);
        $text = new ColumnFit($styles, new AutoFit(), [], 0);
        $text->text(0, 1, $rendered);

        self::assertSame($text->cols(), $number->cols());
    }

    public function testFontSizeAndBoldScaleTheWidth(): void
    {
        $styles = new StyleRegistry();
        $fit = new ColumnFit($styles, new AutoFit(), [], 0);
        $fit->text($styles->add(Style::create()->fontSize(22)), 1, 'abc');
        $fit->text($styles->add(Style::create()->bold()), 2, 'abc');
        $fit->text(0, 3, 'abc');

        // 'abc' is 21 px: (21 * 2 + 2) / 7, (21 * 1.1 + 2) / 7 and (21 + 2) / 7, rounded up to 1/100.
        self::assertSame(
            '<cols><col min="1" max="1" width="6.29" customWidth="1"/><col min="2" max="2" width="3.59" customWidth="1"/>'
            . '<col min="3" max="3" width="3.29" customWidth="1"/></cols>',
            $fit->cols(),
        );
    }

    public function testSampleCompletion(): void
    {
        $fit = new ColumnFit(new StyleRegistry(), new AutoFit(sampleRows: 2), [], 0);

        self::assertFalse($fit->rowDone(10));
        self::assertTrue($fit->rowDone(10));
        self::assertTrue((new ColumnFit(new StyleRegistry(), new AutoFit(), [], 0))->rowDone(ColumnFit::MAX_SAMPLE_BYTES));
    }
}
