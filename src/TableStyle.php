<?php

declare(strict_types=1);

namespace Vensas\Hypaxel;

use Vensas\Hypaxel\Exception\InvalidArgumentException;

/**
 * A built-in table style, as shown in Excel's table style gallery.
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

    private static function create(string $family, int $number, int $max): self
    {
        if ($number < 1 || $number > $max) {
            throw new InvalidArgumentException(\sprintf('%s table styles are numbered 1 to %d, %d given.', $family, $max, $number));
        }

        return new self('TableStyle' . $family . $number);
    }
}
