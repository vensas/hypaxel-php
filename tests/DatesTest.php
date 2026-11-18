<?php

declare(strict_types=1);

namespace Vensas\Hypaxel\Tests;

use PhpOffice\PhpSpreadsheet\Shared\Date;
use PHPUnit\Framework\Attributes\DataProvider;
use Vensas\Hypaxel\Exception\UnsupportedValueException;
use Vensas\Hypaxel\NumberFormat;
use Vensas\Hypaxel\Options;
use Vensas\Hypaxel\Style;
use Vensas\Hypaxel\Styled;
use Vensas\Hypaxel\Tests\Support\XlsxTestCase;
use Vensas\Hypaxel\Writer;

final class DatesTest extends XlsxTestCase
{
    /**
     * @return iterable<string, array{string, bool, int|float|string}>
     */
    public static function serials(): iterable
    {
        yield '1900: first day' => ['1900-01-01', false, 1];
        yield '1900: day before the phantom leap day' => ['1900-02-28', false, 59];
        yield '1900: day after the phantom leap day' => ['1900-03-01', false, 61];
        yield '1900: modern date' => ['2024-01-01', false, 45292];
        yield '1900: noon' => ['2024-01-01 12:00:00', false, 45292.5];
        yield '1900: six in the morning' => ['2024-01-01 06:00:00', false, 45292.25];
        yield '1900: one second' => ['2024-01-01 00:00:01', false, 45292.0000115741];
        yield '1900: milliseconds' => ['2024-01-01 00:00:00.500', false, 45292.0000057870];
        yield '1900: rounds up to the next day' => ['2024-01-01 23:59:59.999999', false, 45293];
        yield '1900: last day' => ['9999-12-31', false, 2958465];
        yield '1900: before the epoch' => ['1899-12-31', false, '1899-12-31'];
        yield '1900: before the epoch with time' => ['1850-06-15 08:30:00', false, '1850-06-15T08:30:00'];
        yield '1900: before the epoch with microseconds' => ['1850-06-15 08:30:00.250000', false, '1850-06-15T08:30:00.250000'];
        yield '1904: first day' => ['1904-01-01', true, 0];
        yield '1904: modern date' => ['2024-01-01', true, 43830];
        yield '1904: noon' => ['2024-01-01 12:00:00', true, 43830.5];
        yield '1904: last day' => ['9999-12-31', true, 2957003];
        yield '1904: before the epoch' => ['1903-12-31', true, '1903-12-31'];
    }

    #[DataProvider('serials')]
    public function testSerials(string $date, bool $date1904, int|float|string $expected): void
    {
        $bytes = $this->build(static function (Writer $writer) use ($date): void {
            $writer->addWorksheet('Dates')->writeRow([new \DateTimeImmutable($date, new \DateTimeZone('UTC'))]);
        }, new Options(date1904: $date1904));

        self::assertSame($expected, self::value($this->loadSheet($bytes), 'A1'));
    }

    public function testYearTenThousandFallsBackToText(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $date = (new \DateTimeImmutable('9999-12-31', new \DateTimeZone('UTC')))->modify('+1 day');
            $writer->addWorksheet('Dates')->writeRow([$date]);
        });

        self::assertSame('10000-01-01', self::value($this->loadSheet($bytes), 'A1'));
    }

    public function testDatesAreWrittenAsWallClockTimeOfTheirOwnZone(): void
    {
        $instant = new \DateTimeImmutable('2024-07-01 12:00:00', new \DateTimeZone('UTC'));
        $bytes = $this->build(static function (Writer $writer) use ($instant): void {
            $writer->addWorksheet('Zones')->writeRow([
                $instant,
                $instant->setTimezone(new \DateTimeZone('Europe/Berlin')),
                $instant->setTimezone(new \DateTimeZone('America/New_York')),
                \DateTime::createFromImmutable($instant)->setTimezone(new \DateTimeZone('+05:30')),
            ]);
        });
        $sheet = $this->loadSheet($bytes);

        $minutes = static function (mixed $serial): int {
            self::assertIsFloat($serial);
            self::assertSame(45474.0, \floor($serial));

            return (int) \round(\fmod($serial, 1.0) * 1440);
        };
        self::assertSame(12 * 60, $minutes(self::value($sheet, 'A1')));
        self::assertSame(14 * 60, $minutes(self::value($sheet, 'B1')));
        self::assertSame(8 * 60, $minutes(self::value($sheet, 'C1')));
        self::assertSame(17 * 60 + 30, $minutes(self::value($sheet, 'D1')));
    }

    public function testUnstyledDatesGetABuiltInDateFormat(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $sheet = $writer->addWorksheet('Formats');
            $sheet->writeRow([new \DateTimeImmutable('2024-05-01'), new \DateTimeImmutable('2024-05-01 13:30:00')]);
            $sheet->writeRow([new \DateTimeImmutable('2024-05-02'), new \DateTimeImmutable('2024-05-02 08:00:00')]);
        });
        $styles = $this->part($bytes, 'xl/styles.xml');
        $xml = $this->part($bytes, 'xl/worksheets/sheet1.xml');

        self::assertStringContainsString('<cellXfs count="3">', $styles);
        self::assertStringContainsString('<xf numFmtId="14" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>', $styles);
        self::assertStringContainsString('<xf numFmtId="22" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>', $styles);
        self::assertStringContainsString('<c s="1"><v>45413</v></c><c s="2"><v>45413.5625</v></c>', $xml);
        self::assertTrue(Date::isDateTime($this->loadSheet($bytes)->getCell('A1')));
        self::assertTrue(Date::isDateTime($this->loadSheet($bytes)->getCell('B2')));
    }

    public function testStyledDatesKeepTheirStyleAndGainADateFormatWhenItHasNone(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $bold = $writer->addStyle(Style::create()->bold());
            $iso = $writer->addStyle(Style::create()->numberFormat(NumberFormat::DATE));
            $date = new \DateTimeImmutable('2024-05-01');
            $sheet = $writer->addWorksheet('Styled');
            $sheet->writeRow([new Styled($date, $bold), new Styled($date, $iso)]);
            $sheet->writeRow([$date, $date], $bold);
        });
        $sheet = $this->loadSheet($bytes);

        self::assertTrue($sheet->getStyle('A1')->getFont()->getBold());
        self::assertTrue(Date::isDateTime($sheet->getCell('A1')));
        self::assertSame('yyyy-mm-dd', $sheet->getStyle('B1')->getNumberFormat()->getFormatCode());
        self::assertFalse($sheet->getStyle('B1')->getFont()->getBold());
        self::assertTrue($sheet->getStyle('B2')->getFont()->getBold());
        self::assertTrue(Date::isDateTime($sheet->getCell('B2')));
    }

    public function testThe1904FlagIsWrittenToTheWorkbook(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $writer->addWorksheet('Dates')->writeRow([new \DateTimeImmutable('2024-01-01')]);
        }, new Options(date1904: true));

        self::assertStringContainsString('<workbookPr date1904="1"/>', $this->part($bytes, 'xl/workbook.xml'));
        self::assertSame(Date::CALENDAR_MAC_1904, $this->load($bytes)->getExcelCalendar());
    }

    public function testIntervalsAreWrittenAsDurations(): void
    {
        $bytes = $this->build(static function (Writer $writer): void {
            $from = new \DateTimeImmutable('2024-01-01 00:00:00', new \DateTimeZone('UTC'));
            $writer->addWorksheet('Durations')->writeRow([
                new \DateInterval('PT36H'),
                new \DateInterval('P2DT6H30M'),
                $from->diff($from->modify('+3 months')),
                $from->modify('+90 minutes')->diff($from),
                new \DateInterval('PT1S'),
            ]);
        });
        $sheet = $this->loadSheet($bytes);

        self::assertSame(1.5, self::value($sheet, 'A1'));
        self::assertSame(2 + 6.5 / 24, self::value($sheet, 'B1'));
        self::assertSame(91, self::value($sheet, 'C1'));
        self::assertSame(-0.0625, self::value($sheet, 'D1'));
        self::assertEqualsWithDelta(1 / 86400, self::value($sheet, 'E1'), 1e-15);
        self::assertStringContainsString('<xf numFmtId="46"', $this->part($bytes, 'xl/styles.xml'));
    }

    public function testIntervalsWithMonthsOrYearsAreRejected(): void
    {
        $sheet = Writer::inMemory()->addWorksheet('Durations');

        $this->expectException(UnsupportedValueException::class);
        $this->expectExceptionMessage('no fixed length');
        $sheet->writeRow([new \DateInterval('P1M')]);
    }
}
