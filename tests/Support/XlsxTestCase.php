<?php

declare(strict_types=1);

namespace Vensas\Hypaxel\Tests\Support;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet as LoadedSheet;
use PHPUnit\Framework\TestCase;
use Vensas\Hypaxel\Options;
use Vensas\Hypaxel\Writer;

abstract class XlsxTestCase extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        // PhpSpreadsheet keeps the date system of the last workbook it loaded in a static.
        Date::setExcelCalendar(Date::CALENDAR_WINDOWS_1900);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (\is_file($file)) {
                \unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    /**
     * @param callable(Writer): void $fill
     */
    protected function build(callable $fill, ?Options $options = null): string
    {
        $writer = Writer::inMemory($options);
        $fill($writer);

        return $writer->finish();
    }

    protected function tempFile(string $contents = ''): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'hypaxel-test-');
        self::assertIsString($path);
        $this->tempFiles[] = $path;
        if ($contents !== '') {
            \file_put_contents($path, $contents);
        }

        return $path;
    }

    protected function load(string $bytes): Spreadsheet
    {
        return IOFactory::createReader('Xlsx')->load($this->tempFile($bytes));
    }

    protected function loadSheet(string $bytes, int $index = 0): LoadedSheet
    {
        return $this->load($bytes)->getSheet($index);
    }

    /**
     * The cell's value with inline strings as plain text.
     */
    protected static function value(LoadedSheet $sheet, string $cell): mixed
    {
        $value = $sheet->getCell($cell)->getValue();

        return $value instanceof RichText ? $value->getPlainText() : $value;
    }

    /**
     * @return list<list<mixed>>
     */
    protected static function values(LoadedSheet $sheet): array
    {
        $rows = [];
        foreach ($sheet->toArray(null, false, false) as $row) {
            $rows[] = \array_map(static fn(mixed $v): mixed => $v instanceof RichText ? $v->getPlainText() : $v, \array_values($row));
        }

        return $rows;
    }

    protected function part(string $bytes, string $name): string
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->tempFile($bytes)));
        $contents = $zip->getFromName($name);
        $zip->close();
        self::assertIsString($contents, "Part {$name} is missing");

        return $contents;
    }

    /**
     * @return list<string>
     */
    protected function partNames(string $bytes): array
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->tempFile($bytes)));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            self::assertIsString($name);
            $names[] = $name;
        }
        $zip->close();

        return $names;
    }
}
