<?php

declare(strict_types=1);

namespace Vensas\Hypaxel;

use Vensas\Hypaxel\Exception\InvalidArgumentException;

final readonly class Font
{
    public const DEFAULT_NAME = 'Calibri';
    public const DEFAULT_SIZE = 11.0;

    public ?Color $color;

    /**
     * @param float $size in points, 1..409
     */
    public function __construct(
        public string $name = self::DEFAULT_NAME,
        public float $size = self::DEFAULT_SIZE,
        public bool $bold = false,
        public bool $italic = false,
        public bool $underline = false,
        public bool $strikethrough = false,
        Color|string|null $color = null,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('A font needs a name.');
        }
        if (!($size >= 1.0 && $size <= 409.0)) {
            throw new InvalidArgumentException('Font size must be within 1..409 points.');
        }
        $this->color = $color === null ? null : Color::from($color);
    }

    /**
     * @internal
     */
    public function key(): string
    {
        return $this->name . "\0" . (int) \round($this->size * 100) . '/' . (int) $this->bold . (int) $this->italic
            . (int) $this->underline . (int) $this->strikethrough . '/' . ($this->color === null ? '' : $this->color->argb);
    }
}
