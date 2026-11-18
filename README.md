# Hypaxel for PHP

**High-performance, constant-memory XLSX (Excel) writer for PHP. Pure PHP, installed with Composer alone.**

Hypaxel writes `.xlsx` files as a forward-only stream. Each row is serialized to XML the moment
you write it and compressed straight into a small buffer that is flushed to the destination, so
memory stays flat whether you export 10 rows or a million, and a download can start while your
query is still running.

- **Fast:** writes 100k rows × 10 columns in about 0.4 s, about **11x faster than OpenSpout** and
  **40x faster than PhpSpreadsheet** (see [Performance](#performance)).
- **Constant memory:** about 200 KiB of PHP heap per export, for 20,000 rows and for 400,000 alike.
- **Streams anywhere:** files, `php://output`, sockets, PSR-7 streams, callbacks, generators. The
  destination does not have to be seekable; the ZIP container is written front to back, with
  automatic ZIP64.
- **Data you already have:** arrays, generators, database cursors, associative arrays and objects.
- **Real Excel types:** `int`, `float`, `string`, `bool`, `DateTimeInterface`, `DateInterval`,
  formulas, enums, `Stringable`. `null` is an empty cell.
- **Styles and layout:** fonts, fills, borders, alignment, number formats, column widths, row
  heights, merged cells, frozen panes, auto filters, hidden sheets, tab colors.
- **Valid output:** every feature is read back with PhpSpreadsheet and OpenSpout in the test
  suite.
- **No extensions to build:** needs PHP 8.2+ (64-bit) and the bundled `zlib` extension. No runtime
  dependencies.

This is a native PHP port of the [`hypaxel` Rust crate](../rust/README.md); the
[.NET](../dotnet/README.md), [Python](../python/README.md) and [Node.js](../node/README.md)
versions use the same design.

> Status: **Milestone 1: creating Excel files.**

## Install

```bash
composer require vensas/hypaxel        # not published yet: see "Development"
```

Requires PHP 8.2 or later (64-bit) with `ext-zlib`.

## Quick start

```php
use Vensas\Hypaxel\ColumnSpec;
use Vensas\Hypaxel\Xlsx;

$orders = [
    ['id' => 1, 'customer' => 'Ada', 'amount' => 1234.5, 'created' => new DateTimeImmutable()],
    ['id' => 2, 'customer' => 'Linus', 'amount' => 99.99, 'created' => new DateTimeImmutable()],
];

Xlsx::writeFile('orders.xlsx', $orders);

$bytes = Xlsx::toString($orders, sheetName: 'Orders', columns: [
    'id',
    new ColumnSpec('customer', header: 'Customer', width: 30),
    new ColumnSpec('amount', numberFormat: '#,##0.00'),
]);
```

Records (associative arrays or objects) get a styled, frozen header row and an auto filter.
`columns` selects and orders the keys; without it the keys of the first record are used. Lists
are written as plain rows, and `columns` then supplies the header texts:

```php
Xlsx::writeFile('matrix.xlsx', [[1, 'a'], [2, 'b']], columns: ['Id', 'Label']);
```

The data can be any `iterable`. Pass a generator or a database cursor and rows are fetched while
the file is being written:

```php
$rows = $pdo->query('SELECT id, customer, amount FROM orders', PDO::FETCH_ASSOC);
Xlsx::writeFile('orders.xlsx', $rows);
```

## Streaming HTTP responses

**Plain PHP**

```php
use Vensas\Hypaxel\Xlsx;

Xlsx::download('orders.xlsx', $pdo->query('SELECT id, customer, amount FROM orders', PDO::FETCH_ASSOC));
```

`download()` sends `Content-Type` and `Content-Disposition` and streams the workbook to the
client.

**Symfony**

```php
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Vensas\Hypaxel\Xlsx;

#[Route('/orders.xlsx')]
public function export(Connection $db): StreamedResponse
{
    $orders = $db->iterateAssociative('SELECT id, customer, amount FROM orders');

    return new StreamedResponse(static fn () => Xlsx::output($orders), 200, Xlsx::headers('orders.xlsx'));
}
```

**Laravel**

```php
use Illuminate\Support\Facades\DB;
use Vensas\Hypaxel\Xlsx;

Route::get('/orders.xlsx', fn () => response()->streamDownload(
    static fn () => Xlsx::output(DB::table('orders')->select('id', 'customer', 'amount')->orderBy('id')->cursor()),
    'orders.xlsx',
    ['Content-Type' => Xlsx::CONTENT_TYPE],
));
```

**PSR-7 / PSR-15** (Slim, Mezzio, ...): write to the body stream through a callback. No PSR
package is needed by Hypaxel itself.

```php
use Vensas\Hypaxel\Writer;
use Vensas\Hypaxel\Xlsx;

$body = $response->getBody();
$writer = Writer::toCallback($body->write(...));
$writer->writeWorksheet('Orders', $orders);
$writer->finish();

return $response->withHeader('Content-Type', Xlsx::CONTENT_TYPE);
```

**Pull-based bodies** (ReactPHP, Amp, Swoole, Symfony's iterable `StreamedResponse`):
`Xlsx::stream($orders)` is a generator that yields the file in chunks while it consumes its
source lazily.

| Helper | Destination |
| --- | --- |
| `Xlsx::writeFile($path, $data, ...)` | a file |
| `Xlsx::writeStream($resource, $data, ...)` | any writable stream resource |
| `Xlsx::toString($data, ...)` | a string (memory grows with the file) |
| `Xlsx::stream($data, ...)` | a generator of chunks |
| `Xlsx::output($data, ...)` | the response body, without headers |
| `Xlsx::download($filename, $data, ...)` | the response body, with headers |
| `Xlsx::headers($filename)`, `Xlsx::contentDisposition($filename)`, `Xlsx::CONTENT_TYPE` | header values for frameworks |

All of them take `sheetName`, `columns`, `header`, `autoFilter` and `options` as named arguments.

Streaming only reaches the client if nothing in between collects the response: turn off
`zlib.output_compression` and user-level output buffering for the request (`.xlsx` is already
compressed), and tell a buffering proxy such as nginx to pass the response through, for example
with the `X-Accel-Buffering: no` header.

## Full control with `Writer`

```php
use Vensas\Hypaxel\Formula;
use Vensas\Hypaxel\HorizontalAlignment;
use Vensas\Hypaxel\SheetOptions;
use Vensas\Hypaxel\Style;
use Vensas\Hypaxel\Styled;
use Vensas\Hypaxel\Writer;

$writer = Writer::toFile('report.xlsx');
$header = $writer->addStyle(Style::create()->bold()->fontColor('#FFFFFF')->fill('#305496')->align(HorizontalAlignment::Center));
$money = $writer->addStyle(Style::create()->numberFormat('#,##0.00'));

$sheet = $writer->addWorksheet('Sales', new SheetOptions(freezeRows: 1, columnWidths: [20, 12, 14]));
$sheet->writeRow(['Product', 'Date', 'Revenue'], $header);
$sheet->writeRows([
    ['Widget', new DateTimeImmutable('2024-05-01'), new Styled(1234.5, $money)],
    ['Gadget', new DateTimeImmutable('2024-05-02'), new Styled(99.99, $money)],
]);
$sheet->writeRow(['Total', null, new Styled(new Formula('SUM(C2:C3)'), $money)]);

$writer->finish();
```

Worksheets are written one at a time: adding a worksheet completes the previous one. Always call
`finish()`; it writes the parts that make the file a workbook.

**Destinations**

| Constructor | Destination |
| --- | --- |
| `Writer::toFile($path)` | creates or truncates a file; `finish()` closes it |
| `Writer::toStream($resource)` | any writable stream resource (`php://output`, `php://memory`, sockets, ...); stays open |
| `Writer::toCallback($callable)` | every chunk goes to `callable(string): mixed` |
| `Writer::toOutput()` | `echo` plus `flush()` per chunk |
| `Writer::inMemory()` | kept in memory; `finish()` returns the bytes, `drain()` takes what exists so far |
| `new Writer($sink)` | your own `Vensas\Hypaxel\Sink\Sink` (`write(string)`, `close()`) |

**Writer**

| Method | Purpose |
| --- | --- |
| `addStyle(Style $style): StyleId` | Registers a format. Identical styles share a handle |
| `addWorksheet($name, ?SheetOptions $options): Worksheet` | Starts the next worksheet |
| `writeWorksheet($name, $data, columns:, header:, autoFilter:): int` | A worksheet from rows or records, with header row |
| `finish(): string` | Completes the workbook; returns the remaining bytes of an in-memory writer |
| `drain(): string`, `flush()` | Take the bytes of an in-memory writer / push buffered bytes to the destination |
| `currentWorksheet()`, `worksheetCount()`, `bytesWritten()`, `isFinished()` | State |

**Worksheet**

| Method | Purpose |
| --- | --- |
| `writeRow(iterable $values, ?StyleId $style)` | One row. `$style` applies to cells without their own |
| `writeRows(iterable $rows, ?StyleId $style): int` | Many rows from any iterable; returns the count |
| `writeRecords(iterable $records, array $columns): int` | One row per array or object, taking `$columns` in order |
| `write($value, ?StyleId $style)`, `endRow()` | Cell-by-cell writing (chainable) |
| `beginRow(?float $height, ?StyleId $style)`, `beginRowAt($row, ...)` | Start a row with a height or row format, or further down |
| `skipCells($n)`, `skipRows($n)` | Leave cells or rows empty |
| `mergeCells($r1, $c1, $r2, $c2)`, `autoFilter($r1, $c1, $r2, $c2)` | Ranges, 1-based and inclusive |
| `row()`, `column()` | Current position |

**Cell values**

| PHP value | Excel cell |
| --- | --- |
| `string` | text (inline, or shared with `sharedStrings`) |
| `int`, `float` | number. `NAN` and `INF` become `#NUM!` |
| `bool` | boolean |
| `null` | empty; with a style, an empty cell carrying the format |
| `DateTimeInterface` | date serial, see [Dates](#dates) |
| `DateInterval` | duration in days, format `[h]:mm:ss` |
| `new Formula('SUM(A1:A9)')` | formula; a leading `=` is optional |
| `new Styled($value, $style)` | any of the above with its own format |
| backed enum, unit enum, `Stringable` | the enum's value, the case name, the string |
| arrays, other objects | `UnsupportedValueException` |

**Styles**

```php
use Vensas\Hypaxel\{Alignment, Border, BorderLine, BorderStyle, Font, HorizontalAlignment, NumberFormat, Style, VerticalAlignment};

$fluent = Style::create()
    ->fontName('Arial')->fontSize(12)->bold()->italic()->underline()->strikethrough()->fontColor('#C00000')
    ->fill('#FFF2CC')
    ->border(BorderStyle::Thin, '#808080')
    ->align(HorizontalAlignment::Right)->valign(VerticalAlignment::Center)->wrap()->indent(1)->rotation(45)
    ->numberFormat('0.00%');

$explicit = new Style(
    numberFormat: NumberFormat::THOUSANDS2,
    font: new Font(name: 'Arial', size: 12, bold: true, color: '#C00000'),
    fill: '#FFF2CC',
    border: new Border(bottom: new BorderLine(BorderStyle::Double, '#000000')),
    alignment: new Alignment(wrapText: true),
);
```

Styles are immutable value objects; every modifier returns a new instance. Colors are `#RRGGBB`
or `#AARRGGBB` strings or `Color` objects. `BorderStyle` has the 13 line styles of the format.
`NumberFormat` has constants for common format codes; any Excel format code works.

**Worksheet options**

```php
new SheetOptions(
    freezeRows: 1,                 // rows pinned at the top
    freezeColumns: 0,              // columns pinned at the left
    columnWidths: [20, null, 14],  // from column A; null keeps the default
    columns: [5 => new Column(width: 30, style: $money, hidden: true)],  // by 1-based column number
    showGridLines: true,
    hidden: false,
    tabColor: '#305496',
);
```

## Options

```php
$writer = Writer::toFile('report.xlsx', new Options(sharedStrings: true));
```

| Option | Default | Meaning |
| --- | --- | --- |
| `compressionLevel` | `1` | Deflate level 0-9 |
| `sharedStrings` | `false` | Shared string table: smaller files for repeated text, memory grows with the number of distinct strings |
| `flushThreshold` | `65536` | Compressed bytes buffered before a chunk goes to the destination (at least 1024) |
| `cellReferences` | `false` | Write a reference such as `B7` on every cell |
| `date1904` | `false` | Use the 1904 date system |

## Dates

Excel has no time zones. A `DateTimeInterface` is written as the wall-clock time of the object's
own time zone: `2024-07-01 14:00 Europe/Berlin` becomes `2024-07-01 14:00` in the sheet. Convert
with `setTimezone()` first if you want another zone.

Without a number format, a value at midnight gets Excel's locale-dependent short date format and
any other value the short date-and-time format. Give the cell or the column a format such as
`NumberFormat::DATE` to control it. Dates the date system cannot hold (before 1900, before 1904
with `date1904`, after 9999) are written as ISO 8601 text.

A `DateInterval` is written as a duration when it has a fixed length: one from `diff()`, or one
without years and months. Others throw an `UnsupportedValueException`, because a month has no
fixed number of days.

## Notes and limits

- **Write order:** rows go top to bottom, cells left to right, one worksheet at a time. Column
  widths and frozen panes are set when the worksheet is added.
- **Excel limits apply:** 1,048,576 rows, 16,384 columns and 32,767 characters per cell. Exceeding
  them throws a `LimitExceededException`. A row rejected by `writeRow`/`writeRows` is not written
  at all, so the file stays valid if you catch the exception and continue.
- **Exceptions** all extend `Vensas\Hypaxel\Exception\XlsxException`: `InvalidArgumentException`,
  `InvalidSheetNameException`, `DuplicateSheetNameException`, `LimitExceededException`,
  `UnsupportedValueException`, `WriterStateException`, `IoException`.
- **Text encoding:** strings must be UTF-8. Invalid byte sequences are replaced with U+FFFD
  instead of producing a file Excel refuses to open. Control characters and carriage returns are
  written with Excel's `_xHHHH_` escape and read back unchanged.
- **Numbers:** every Excel number is a double, so integers beyond ±2^53 lose precision. Floats are
  written in their shortest form that reads back identically.
- **Formulas** are stored without cached results; Excel recalculates them when the file opens.
- **Readers:** by default, cell references that follow from a cell's position are omitted, which
  the format allows and which makes files smaller and faster to write. References are written
  for the rest of a row after an empty cell and for the rest of a worksheet after a skipped row,
  which is what PhpSpreadsheet needs to place cells correctly. The test suite reads the default
  output back with PhpSpreadsheet and OpenSpout. ExcelJS fails on omitted references (see the
  [Node.js notes](../node/README.md#notes-and-limits)); pass `cellReferences: true` for such readers.
- **Records:** arrays, `ArrayAccess` objects and public properties are read by key. Use a
  `ColumnSpec` with a `value` callable for getters: `new ColumnSpec('total', value: fn (Order $o) => $o->total())`.
- **Styles** belong to the writer that created them.
- **Merged cells** are kept in memory until their worksheet completes.

## Performance

Writing 10 columns per row (int, two strings, three floats, a `DateTimeImmutable`, a bool, a
second int and a string that is unique per row) from a generator to a file. Each case runs in its
own PHP process; "peak memory" is `memory_get_peak_usage(true)` of that process, which includes
the autoloader and the library's classes.

Measured on an Apple M1 Max (macOS 26.6) with PHP 8.4.8 (CLI, NTS) against OpenSpout 5.14.1 and
PhpSpreadsheet 5.11.0.

**opcache off** (the CLI default)

| Case | 100k rows | Peak memory | 1M rows | Peak memory |
| --- | ---: | ---: | ---: | ---: |
| Hypaxel, rows | 0.42 s | 4.0 MB | 4.17 s | 4.0 MB |
| Hypaxel, records with header | 0.57 s | 4.0 MB | 5.38 s | 4.0 MB |
| Hypaxel, rows, shared strings | 0.44 s | 13.5 MB | 4.09 s | 90.0 MB |
| Hypaxel, rows, `Xlsx::stream()` | 0.45 s | 4.0 MB | 4.92 s | 4.0 MB |
| OpenSpout | 4.60 s | 4.0 MB | 47.83 s | 4.0 MB |
| PhpSpreadsheet | 17.20 s | 661.1 MB | not run | not run |

**opcache with tracing JIT** (`opcache.enable_cli=1`, `opcache.jit=tracing`, `opcache.jit_buffer_size=128M`)

| Case | 100k rows | Peak memory | 1M rows | Peak memory |
| --- | ---: | ---: | ---: | ---: |
| Hypaxel, rows | 0.30 s | 4.0 MB | 2.93 s | 4.0 MB |
| Hypaxel, records with header | 0.37 s | 4.0 MB | 3.64 s | 4.0 MB |
| Hypaxel, rows, shared strings | 0.31 s | 13.5 MB | 3.07 s | 90.0 MB |
| Hypaxel, rows, `Xlsx::stream()` | 0.32 s | 4.0 MB | 3.18 s | 4.0 MB |
| OpenSpout | 4.11 s | 4.0 MB | 40.65 s | 4.0 MB |
| PhpSpreadsheet | 11.81 s | 659.1 MB | not run | not run |

These are single runs, taken while the machine was also busy with other work, so treat small
differences as noise. OpenSpout is a streaming writer too
and uses as little memory as Hypaxel; the difference is time. PhpSpreadsheet builds the workbook
in memory and is not run at 1M rows. The 1M-row file is 39 MB from Hypaxel and 52 MB
from OpenSpout.

Run them yourself:

```bash
php benchmarks/bench.php                  # 100,000 rows
php benchmarks/bench.php 1000000
php benchmarks/bench.php 100000 --jit     # opcache + tracing JIT
```

### What makes it fast in PHP

- **One string per row, no objects per cell.** Scalars are serialized inline in the row loop;
  there is no cell object and no method call per cell.
- **One call per string.** A single `preg_match` validates UTF-8 and detects everything that
  needs escaping or `xml:space`; clean text is copied as it is.
- **Compact XML.** Cell references are only written where a reader needs them.
- **Checksums from zlib.** Entries are deflated with gzip framing, which is stripped again, so
  zlib computes the CRC-32 while it compresses. On the test machine, compressing and
  checksumming worksheet XML at level 1 ran at 434 MB/s this way and at 203 MB/s with a
  separate `hash_update('crc32b')` pass.
- **What remains:** formatting floats. PHP has no cheaper way to get the shortest round-trip
  form of a double than `json_encode`, at about 0.2 to 0.3 µs per value; float-heavy sheets are
  bounded by it.

## Development

```bash
cd php
composer install
composer test          # PHPUnit; reads every file back with PhpSpreadsheet and OpenSpout
composer analyse       # PHPStan, level max with strict rules
composer cs            # PHP-CS-Fixer (PER-CS), dry run
composer bench
```

Coverage needs the `pcov` or `xdebug` extension: `composer test:coverage`.

`composer.lock` is not committed, as usual for a library: CI resolves the newest dependencies
each PHP version allows.

## License

Licensed under the [Apache License, Version 2.0](LICENSE). Copyright 2026 vensas GmbH.
