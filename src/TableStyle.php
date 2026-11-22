<?php

declare(strict_types=1);

namespace Vensas\Hypaxel;

use Vensas\Hypaxel\Exception\InvalidArgumentException;
use Vensas\Hypaxel\Internal\Xml;

/**
 * A table style: one of Excel's built-in gallery styles, or a custom style by name.
 */
final readonly class TableStyle
{
    private function __construct(public ?string $name) {}

    /**
     * No table formatting; filter buttons and structured references still work.
     */
    public static function none(): self
    {
        return new self(null);
    }

    /**
     * `TableStyleMedium2`, Excel's default for new tables.
     */
    public static function default(): self
    {
        return new self('TableStyleMedium2');
    }

    /**
     * @param int $number 1..21
     */
    public static function light(int $number): self
    {
        return self::create('Light', $number, 21);
    }

    /**
     * @param int $number 1..28
     */
    public static function medium(int $number): self
    {
        return self::create('Medium', $number, 28);
    }

    /**
     * @param int $number 1..11
     */
    public static function dark(int $number): self
    {
        return self::create('Dark', $number, 11);
    }

    /**
     * A custom table style, referenced by the name it has in the workbook. The name is written as
     * given; Excel falls back to no formatting when the workbook does not define the style.
     *
     * @param string $name 1 to 255 characters on a single line, no XML-invalid characters
     *
     * @throws InvalidArgumentException when the name is empty, too long or contains control characters
     */
    public static function custom(string $name): self
    {
        if ($name === '' || \preg_match('/^[^\x00-\x1F\x{FFFE}\x{FFFF}]+$/Du', $name) !== 1 || Xml::utf16Length($name) > 255) {
            throw new InvalidArgumentException('Invalid table style name: custom style names have 1 to 255 characters and no line breaks, tabs or control characters.');
        }

        return new self($name);
    }

    private static function create(string $family, int $number, int $max): self
    {
        if ($number < 1 || $number > $max) {
            throw new InvalidArgumentException(\sprintf('%s table styles are numbered 1 to %d, %d given.', $family, $max, $number));
        }

        return new self('TableStyle' . $family . $number);
    }
}
