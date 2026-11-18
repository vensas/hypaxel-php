<?php

declare(strict_types=1);

namespace Vensas\Hypaxel;

use Vensas\Hypaxel\Exception\WriterStateException;
use Vensas\Hypaxel\Internal\TableWriter;

/**
 * One-call exports of a single worksheet. For several worksheets, styles or merged cells use
 * {@see Writer} directly.
 *
 * `$data` is any iterable (array, generator, database cursor) of records or rows:
 * records (associative arrays or objects) get a styled, frozen header row and an auto filter, and
 * `$columns` selects and orders their keys (default: the keys of the first record); rows (lists)
 * are written as they are, and `$columns` supplies the header texts.
 */
final class Xlsx
{
    public const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    public const DEFAULT_SHEET_NAME = 'Sheet1';

    private function __construct() {}

    /**
     * @param iterable<mixed>              $data
     * @param list<string|ColumnSpec>|null $columns
     */
    public static function writeFile(
        string $path,
        iterable $data,
        string $sheetName = self::DEFAULT_SHEET_NAME,
        ?array $columns = null,
        bool $header = true,
        bool $autoFilter = true,
        ?Options $options = null,
    ): void {
        $writer = Writer::toFile($path, $options);
        $writer->writeWorksheet($sheetName, $data, $columns, $header, $autoFilter);
        $writer->finish();
    }

    /**
     * Writes to an open stream resource, which stays open.
     *
     * @param resource                     $stream
     * @param iterable<mixed>              $data
     * @param list<string|ColumnSpec>|null $columns
     */
    public static function writeStream(
        mixed $stream,
        iterable $data,
        string $sheetName = self::DEFAULT_SHEET_NAME,
        ?array $columns = null,
        bool $header = true,
        bool $autoFilter = true,
        ?Options $options = null,
    ): void {
        $writer = Writer::toStream($stream, $options);
        $writer->writeWorksheet($sheetName, $data, $columns, $header, $autoFilter);
        $writer->finish();
    }

    /**
     * Returns the complete file as a string. Memory grows with the file; prefer the streaming
     * variants for large exports.
     *
     * @param iterable<mixed>              $data
     * @param list<string|ColumnSpec>|null $columns
     */
    public static function toString(
        iterable $data,
        string $sheetName = self::DEFAULT_SHEET_NAME,
        ?array $columns = null,
        bool $header = true,
        bool $autoFilter = true,
        ?Options $options = null,
    ): string {
        $writer = Writer::inMemory($options);
        $writer->writeWorksheet($sheetName, $data, $columns, $header, $autoFilter);

        return $writer->finish();
    }

    /**
     * Yields the file in chunks while consuming `$data` lazily, for frameworks that take an iterable
     * or pull-based response body.
     *
     * @param iterable<mixed>              $data
     * @param list<string|ColumnSpec>|null $columns
     *
     * @return \Generator<int, string, mixed, void>
     */
    public static function stream(
        iterable $data,
        string $sheetName = self::DEFAULT_SHEET_NAME,
        ?array $columns = null,
        bool $header = true,
        bool $autoFilter = true,
        ?Options $options = null,
    ): \Generator {
        $writer = Writer::inMemory($options);
        $table = new TableWriter($writer, $sheetName, $columns, $header, $autoFilter);
        $threshold = ($options ?? new Options())->flushThreshold;
        $pending = 0;
        foreach ($data as $item) {
            $table->write($item);
            if ($writer->bytesWritten() - $pending >= $threshold) {
                $pending = $writer->bytesWritten();
                yield $writer->drain();
            }
        }
        $table->finish();
        $rest = $writer->finish();
        if ($rest !== '') {
            yield $rest;
        }
    }

    /**
     * Echoes the file to the response body, flushing chunks to the client as they are produced.
     * Sends no headers: use it inside a framework's streamed response and take the headers from
     * {@see headers()}.
     *
     * @param iterable<mixed>              $data
     * @param list<string|ColumnSpec>|null $columns
     */
    public static function output(
        iterable $data,
        string $sheetName = self::DEFAULT_SHEET_NAME,
        ?array $columns = null,
        bool $header = true,
        bool $autoFilter = true,
        ?Options $options = null,
    ): void {
        $writer = Writer::toOutput($options);
        $writer->writeWorksheet($sheetName, $data, $columns, $header, $autoFilter);
        $writer->finish();
    }

    /**
     * Sends the download headers and streams the file to the client (plain PHP, no framework).
     *
     * @param iterable<mixed>              $data
     * @param list<string|ColumnSpec>|null $columns
     */
    public static function download(
        string $filename,
        iterable $data,
        string $sheetName = self::DEFAULT_SHEET_NAME,
        ?array $columns = null,
        bool $header = true,
        bool $autoFilter = true,
        ?Options $options = null,
    ): void {
        self::sendHeaders($filename);
        self::output($data, $sheetName, $columns, $header, $autoFilter, $options);
    }

    /**
     * Sends the response headers of a download. They must go out before any other output.
     */
    public static function sendHeaders(string $filename): void
    {
        // @codeCoverageIgnoreStart
        if (\headers_sent($file, $line)) {
            throw new WriterStateException(\sprintf('The download headers cannot be sent: output already started at %s:%d.', $file, $line));
        }
        // @codeCoverageIgnoreEnd
        foreach (self::headers($filename) as $name => $value) {
            \header($name . ': ' . $value);
        }
    }

    /**
     * Response headers of a download.
     *
     * @return array{Content-Type: string, Content-Disposition: string, X-Content-Type-Options: string}
     */
    public static function headers(string $filename): array
    {
        return [
            'Content-Type' => self::CONTENT_TYPE,
            'Content-Disposition' => self::contentDisposition($filename),
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    /**
     * An `attachment` Content-Disposition value (RFC 6266) with an ASCII fallback name; `.xlsx` is
     * appended when missing. Path separators, quotes and control characters are removed.
     */
    public static function contentDisposition(string $filename): string
    {
        $name = \preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/', '', \basename($filename)) ?? '';
        $name = \trim($name);
        if ($name === '' || $name === '.xlsx') {
            $name = 'export.xlsx';
        } elseif (!\str_ends_with(\strtolower($name), '.xlsx')) {
            $name .= '.xlsx';
        }
        $ascii = \preg_replace('/[^\x20-\x7E]/u', '_', $name) ?? \preg_replace('/[^\x20-\x7E]/', '_', $name) ?? 'export.xlsx';
        $ascii = \str_replace('%', '_', $ascii);
        $value = 'attachment; filename="' . $ascii . '"';

        return $ascii === $name ? $value : $value . "; filename*=UTF-8''" . \rawurlencode($name);
    }
}
