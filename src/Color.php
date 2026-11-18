<?php

declare(strict_types=1);

namespace Vensas\Hypaxel;

use Vensas\Hypaxel\Exception\InvalidArgumentException;

/**
 * An ARGB color. Wherever a color is expected, a hex string such as `#305496` works as well.
 */
final readonly class Color
{
    public const BLACK = '#000000';
    public const WHITE = '#FFFFFF';
    public const RED = '#FF0000';
    public const GREEN = '#008000';
    public const BLUE = '#0000FF';
    public const YELLOW = '#FFFF00';
    public const LIGHT_GRAY = '#D9D9D9';
    public const DARK_GRAY = '#595959';

    private function __construct(public int $argb) {}

    public static function rgb(int $red, int $green, int $blue, int $alpha = 255): self
    {
        foreach ([$red, $green, $blue, $alpha] as $channel) {
            if ($channel < 0 || $channel > 255) {
                throw new InvalidArgumentException('Color channels must be within 0..255.');
            }
        }

        return new self($alpha << 24 | $red << 16 | $green << 8 | $blue);
    }

    /**
     * Parses `#RRGGBB`, `RRGGBB`, `#AARRGGBB` or `AARRGGBB`.
     */
    public static function hex(string $hex): self
    {
        $digits = \ltrim($hex, '#');
        $length = \strlen($digits);
        if (($length !== 6 && $length !== 8) || !\ctype_xdigit($digits)) {
            throw new InvalidArgumentException(\sprintf('Invalid color "%s": expected #RRGGBB or #AARRGGBB.', $hex));
        }
        $value = (int) \hexdec($digits);

        return new self($length === 6 ? 0xFF000000 | $value : $value);
    }

    public static function from(self|string $color): self
    {
        return $color instanceof self ? $color : self::hex($color);
    }

    /**
     * Eight upper-case hex digits (`AARRGGBB`), as written to the file.
     */
    public function toHex(): string
    {
        return \sprintf('%08X', $this->argb);
    }
}
