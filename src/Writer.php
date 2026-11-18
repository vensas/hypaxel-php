<?php

declare(strict_types=1);

namespace Vensas\Hypaxel;

use Vensas\Hypaxel\Exception\DuplicateSheetNameException;
use Vensas\Hypaxel\Exception\InvalidSheetNameException;
use Vensas\Hypaxel\Exception\IoException;
use Vensas\Hypaxel\Exception\WriterStateException;
use Vensas\Hypaxel\Internal\Parts;
use Vensas\Hypaxel\Internal\SheetInfo;
use Vensas\Hypaxel\Internal\TableWriter;
use Vensas\Hypaxel\Internal\Workbook;
use Vensas\Hypaxel\Internal\Xml;
use Vensas\Hypaxel\Internal\ZipStream;
use Vensas\Hypaxel\Sink\CallbackSink;
use Vensas\Hypaxel\Sink\MemorySink;
use Vensas\Hypaxel\Sink\OutputSink;
use Vensas\Hypaxel\Sink\Sink;
use Vensas\Hypaxel\Sink\StreamSink;

/**
 * Forward-only XLSX writer.
 *
 * Rows go top to bottom, cells left to right, one worksheet at a time. Every row is serialized
 * when it is written and compressed into a small buffer that is handed to the destination whenever
 * it passes {@see Options::$flushThreshold}, so memory stays constant regardless of the row count.
 *
 *     $writer = Writer::toFile('report.xlsx');
 *     $sheet = $writer->addWorksheet('Sales');
 *     $sheet->writeRow(['Widget', 3, 9.99]);
 *     $writer->finish();
 */
final class Writer
{
    private readonly Workbook $workbook;
    private readonly ZipStream $zip;
    private ?Worksheet $current = null;
    private bool $finished = false;

    public function __construct(private readonly Sink $sink, ?Options $options = null)
    {
        $options ??= new Options();
        $this->zip = new ZipStream($sink, $options->compressionLevel, $options->flushThreshold);
        $this->workbook = new Workbook($this->zip, $options);
    }

    /**
     * Creates or truncates a file. It is closed by {@see finish()}.
     */
    public static function toFile(string $path, ?Options $options = null): self
    {
        $stream = @\fopen($path, 'wb');
        if ($stream === false) {
            throw new IoException(\sprintf('Cannot open "%s" for writing: %s', $path, \error_get_last()['message'] ?? 'unknown error'));
        }

        return new self(new StreamSink($stream, closeOnFinish: true), $options);
    }

    /**
     * Writes to any writable stream resource: a file, a socket, `php://output`, `php://memory`, ...
     * The stream does not have to be seekable and stays open after {@see finish()}.
     *
     * @param resource $stream
     */
    public static function toStream(mixed $stream, ?Options $options = null): self
    {
        return new self(new StreamSink($stream), $options);
    }

    /**
     * Hands every chunk to a callable, e.g. `Writer::toCallback($psr7Stream->write(...))`.
     *
     * @param callable(string): mixed $write
     */
    public static function toCallback(callable $write, ?Options $options = null): self
    {
        return new self(new CallbackSink($write), $options);
    }

    /**
     * Writes to the response body and flushes every chunk to the client.
     */
    public static function toOutput(?Options $options = null): self
    {
        return new self(new OutputSink(), $options);
    }

    /**
     * Keeps the output in memory: {@see finish()} returns it, {@see drain()} takes what exists so far.
     */
    public static function inMemory(?Options $options = null): self
    {
        return new self(new MemorySink(), $options);
    }

    /**
     * Registers a cell format. Identical styles share a handle.
     */
    public function addStyle(Style $style): StyleId
    {
        return new StyleId($this->workbook->styles->add($style));
    }

    /**
     * Adds a worksheet and returns the handle for writing it. The previous worksheet is completed
     * and cannot be written to anymore.
     */
    public function addWorksheet(string $name, ?SheetOptions $options = null): Worksheet
    {
        $this->assertOpen();
        self::assertSheetName($name);
        $folded = Xml::foldCase($name);
        foreach ($this->workbook->sheets as $sheet) {
            if (Xml::foldCase($sheet->name) === $folded) {
                throw new DuplicateSheetNameException(\sprintf('A worksheet named "%s" already exists.', $name));
            }
        }

        $options ??= new SheetOptions();
        $info = new SheetInfo($name, $options->hidden);
        $index = \count($this->workbook->sheets);
        $worksheet = new Worksheet($this->workbook, $info, $options, $index === 0);

        $this->current?->complete();
        $this->zip->startEntry('xl/worksheets/sheet' . ($index + 1) . '.xml');
        $this->workbook->sheets[] = $info;

        return $this->current = $worksheet;
    }

    /**
     * Adds a worksheet and fills it from rows or records.
     *
     * Records (associative arrays or objects) get a styled, frozen header row and an auto filter;
     * `$columns` selects and orders their keys, by default the keys of the first record. Rows (lists)
     * are written as they are; `$columns` then supplies the header texts.
     *
     * @param iterable<mixed>              $data
     * @param list<string|ColumnSpec>|null $columns
     *
     * @return int number of data rows written
     */
    public function writeWorksheet(
        string $name,
        iterable $data,
        ?array $columns = null,
        bool $header = true,
        bool $autoFilter = true,
    ): int {
        $table = new TableWriter($this, $name, $columns, $header, $autoFilter);
        $count = $table->writeAll($data);
        $table->finish();

        return $count;
    }

    /**
     * The worksheet currently being written, if any.
     */
    public function currentWorksheet(): ?Worksheet
    {
        return $this->finished ? null : $this->current;
    }

    public function worksheetCount(): int
    {
        return \count($this->workbook->sheets);
    }

    /**
     * Total bytes produced so far, including those still buffered.
     */
    public function bytesWritten(): int
    {
        return $this->zip->position();
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }

    /**
     * Hands the compressed bytes buffered so far to the destination.
     */
    public function flush(): void
    {
        $this->zip->flush();
    }

    /**
     * For in-memory writers: returns the bytes produced since the last call and forgets them.
     * Send them on between batches of rows to keep memory flat.
     */
    public function drain(): string
    {
        if (!$this->sink instanceof MemorySink) {
            throw new WriterStateException('drain() is only available for in-memory writers.');
        }
        $this->zip->flush();

        return $this->sink->take();
    }

    /**
     * Completes the workbook and closes the destination. Returns the remaining bytes of an
     * in-memory writer, otherwise an empty string.
     */
    public function finish(): string
    {
        $this->assertOpen();
        if ($this->workbook->sheets === []) {
            $this->addWorksheet('Sheet1');
        }
        $visible = false;
        foreach ($this->workbook->sheets as $sheet) {
            $visible = $visible || !$sheet->hidden;
        }
        if (!$visible) {
            throw new WriterStateException('A workbook needs at least one visible worksheet.');
        }

        $this->current?->complete();
        $this->current = null;
        $this->finished = true;

        $workbook = $this->workbook;
        $sheetCount = \count($workbook->sheets);
        $shared = $workbook->sharedStrings !== null && $workbook->sharedStrings->index !== [];
        if ($shared) {
            $this->writeSharedStrings();
        }
        $this->writePart('xl/styles.xml', $workbook->styles->toXml());
        $this->writePart('xl/workbook.xml', Parts::workbook($workbook->sheets, $workbook->usesFormulas, $workbook->options->date1904));
        $this->writePart('xl/_rels/workbook.xml.rels', Parts::workbookRels($sheetCount, $shared));
        $this->writePart('[Content_Types].xml', Parts::contentTypes($sheetCount, $workbook->tableCount, $shared));
        $this->writePart('_rels/.rels', Parts::rootRels());
        $this->zip->finish();
        $this->sink->close();

        return $this->sink instanceof MemorySink ? $this->sink->take() : '';
    }

    private function writePart(string $name, string $xml): void
    {
        $this->zip->startEntry($name);
        $this->zip->write($xml);
        $this->zip->finishEntry();
    }

    private function writeSharedStrings(): void
    {
        $strings = $this->workbook->sharedStrings;
        \assert($strings !== null);
        $this->zip->startEntry('xl/sharedStrings.xml');
        $x = Xml::DECLARATION . '<sst xmlns="' . Xml::NS_MAIN . '" count="' . $strings->references
            . '" uniqueCount="' . \count($strings->index) . '">';
        foreach ($strings->index as $text => $_) {
            $text = (string) $text;
            $x .= '<si>' . (\preg_match(Xml::NEEDS_CARE, $text) === 0 ? '<t>' . $text . '</t>' : Xml::textElement($text)) . '</si>';
            if (\strlen($x) >= 65536) {
                $this->zip->write($x);
                $x = '';
            }
        }
        $this->zip->write($x . '</sst>');
        $this->zip->finishEntry();
    }

    private function assertOpen(): void
    {
        if ($this->finished) {
            throw new WriterStateException('The workbook is already finished.');
        }
    }

    private static function assertSheetName(string $name): void
    {
        $invalid = $name === ''
            || \preg_match('//u', $name) !== 1
            || Xml::utf16Length($name) > 31
            || \strpbrk($name, ':\\/?*[]') !== false
            || $name[0] === "'"
            || $name[-1] === "'"
            || \strcasecmp($name, 'History') === 0;
        if ($invalid) {
            throw new InvalidSheetNameException(\sprintf(
                'Invalid worksheet name "%s": names have 1 to 31 characters, none of : \\ / ? * [ ], no apostrophe at either end, and "History" is reserved.',
                $name,
            ));
        }
    }
}
