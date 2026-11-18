<?php

declare(strict_types=1);

/*
 * Compares Hypaxel with OpenSpout and PhpSpreadsheet. Every case runs in its own PHP process, so
 * time and peak memory are per case.
 *
 *   php benchmarks/bench.php                 # 100,000 rows
 *   php benchmarks/bench.php 1000000         # 1,000,000 rows (PhpSpreadsheet is skipped)
 *   php benchmarks/bench.php 100000 --jit    # children run with opcache and the tracing JIT
 *   php benchmarks/bench.php 100000 --only=hypaxel
 */

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as OpenSpoutWriter;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as PhpSpreadsheetWriter;
use Vensas\Hypaxel\Options;
use Vensas\Hypaxel\Writer;
use Vensas\Hypaxel\Xlsx;

require __DIR__ . '/../vendor/autoload.php';

const CATEGORIES = ['Hardware', 'Software', 'Services', 'Consulting', 'Support', 'Training'];
const KEYS = ['id', 'customer', 'category', 'price', 'amount', 'created', 'active', 'quantity', 'comment', 'ratio'];
const PHPSPREADSHEET_MAX_ROWS = 200_000;

/**
 * @return Generator<int, list<bool|DateTimeImmutable|float|int|string>>
 */
function rows(int $count): Generator
{
    $start = new DateTimeImmutable('2024-01-01 00:00:00', new DateTimeZone('UTC'));
    for ($i = 0; $i < $count; ++$i) {
        yield [
            $i,
            'Customer ' . ($i % 5000),
            CATEGORIES[$i % 6],
            $i * 1.25,
            $i * 0.01,
            $start->setTimestamp(1_704_067_200 + $i * 60),
            $i % 2 === 0,
            $i * 3,
            'Unique comment #' . $i,
            $i / 7,
        ];
    }
}

/**
 * @return Generator<int, array<string, mixed>>
 */
function records(int $count): Generator
{
    foreach (rows($count) as $row) {
        yield array_combine(KEYS, $row);
    }
}

/**
 * @return array<string, Closure(int, string): void>
 */
function cases(): array
{
    return [
        'Hypaxel, rows' => static function (int $count, string $path): void {
            $writer = Writer::toFile($path);
            $writer->addWorksheet('Data')->writeRows(rows($count));
            $writer->finish();
        },
        'Hypaxel, records with header' => static function (int $count, string $path): void {
            Xlsx::writeFile($path, records($count));
        },
        'Hypaxel, rows, shared strings' => static function (int $count, string $path): void {
            $writer = Writer::toFile($path, new Options(sharedStrings: true));
            $writer->addWorksheet('Data')->writeRows(rows($count));
            $writer->finish();
        },
        'Hypaxel, rows, stream() generator' => static function (int $count, string $path): void {
            $file = fopen($path, 'wb');
            assert($file !== false);
            foreach (Xlsx::stream(rows($count)) as $chunk) {
                fwrite($file, $chunk);
            }
            fclose($file);
        },
        'OpenSpout' => static function (int $count, string $path): void {
            $writer = new OpenSpoutWriter();
            $writer->openToFile($path);
            foreach (rows($count) as $row) {
                $writer->addRow(Row::fromValues($row));
            }
            $writer->close();
        },
        'PhpSpreadsheet' => static function (int $count, string $path): void {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $r = 0;
            foreach (rows($count) as $row) {
                ++$r;
                $row[5] = Date::PHPToExcel($row[5]);
                $sheet->fromArray($row, null, 'A' . $r, true);
            }
            $sheet->getStyle('F1:F' . max($r, 1))->getNumberFormat()->setFormatCode('yyyy-mm-dd hh:mm');
            (new PhpSpreadsheetWriter($spreadsheet))->save($path);
        },
    ];
}

function runCase(string $name, int $count): void
{
    $path = sys_get_temp_dir() . '/hypaxel-bench-' . getmypid() . '.xlsx';
    $start = hrtime(true);
    cases()[$name]($count, $path);
    $seconds = (hrtime(true) - $start) / 1e9;
    clearstatcache();
    echo json_encode(['seconds' => $seconds, 'peak' => memory_get_peak_usage(true), 'bytes' => filesize($path)]);
    @unlink($path);
}

/**
 * @param list<string> $argv
 */
function main(array $argv): int
{
    $count = 100_000;
    $jit = false;
    $only = null;
    $case = null;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--jit') {
            $jit = true;
        } elseif (str_starts_with($arg, '--only=')) {
            $only = substr($arg, 7);
        } elseif (str_starts_with($arg, '--case=')) {
            $case = substr($arg, 7);
        } elseif (ctype_digit($arg)) {
            $count = (int) $arg;
        } else {
            fwrite(STDERR, "Unknown argument: {$arg}\n");

            return 2;
        }
    }

    if ($case !== null) {
        runCase($case, $count);

        return 0;
    }

    $flags = ['-d', 'memory_limit=-1'];
    $flags = array_merge($flags, $jit
        ? ['-d', 'opcache.enable_cli=1', '-d', 'opcache.jit=tracing', '-d', 'opcache.jit_buffer_size=128M']
        : ['-d', 'opcache.enable_cli=0']);

    printf("PHP %s, %s, %s rows x 10 columns\n\n", PHP_VERSION, $jit ? 'opcache + tracing JIT' : 'opcache off', number_format($count));
    printf("%-36s %10s %12s %10s\n", 'Case', 'Time', 'Peak memory', 'File');
    foreach (array_keys(cases()) as $name) {
        if ($only !== null && stripos($name, $only) === false) {
            continue;
        }
        if ($name === 'PhpSpreadsheet' && $count > PHPSPREADSHEET_MAX_ROWS) {
            printf("%-36s %10s\n", $name, 'not run');
            continue;
        }
        $command = array_merge([PHP_BINARY], $flags, [__FILE__, '--case=' . $name, (string) $count]);
        $output = [];
        exec(implode(' ', array_map(escapeshellarg(...), $command)), $output, $status);
        $result = json_decode(implode('', $output), true);
        $seconds = is_array($result) ? $result['seconds'] ?? null : null;
        $peak = is_array($result) ? $result['peak'] ?? null : null;
        $bytes = is_array($result) ? $result['bytes'] ?? null : null;
        if ($status !== 0 || !is_float($seconds) || !is_int($peak) || !is_int($bytes)) {
            printf("%-36s %10s\n", $name, 'failed');
            continue;
        }
        printf("%-36s %8.2f s %9.1f MB %7.1f MB\n", $name, $seconds, $peak / 1048576, $bytes / 1048576);
    }

    return 0;
}

$arguments = $_SERVER['argv'] ?? [];
exit(main(is_array($arguments) ? array_values(array_filter($arguments, is_string(...))) : []));
