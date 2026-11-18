<?php

declare(strict_types=1);

namespace Vensas\Hypaxel;

use Vensas\Hypaxel\Exception\InvalidArgumentException;

final readonly class Alignment
{
    public const VERTICAL_TEXT = 255;

    /**
     * @param int $indent   0..255 indent levels
     * @param int $rotation 0..180 degrees, or {@see self::VERTICAL_TEXT}
     */
    public function __construct(
        public HorizontalAlignment $horizontal = HorizontalAlignment::General,
        public VerticalAlignment $vertical = VerticalAlignment::Bottom,
        public bool $wrapText = false,
        public bool $shrinkToFit = false,
        public int $indent = 0,
        public int $rotation = 0,
    ) {
        if ($indent < 0 || $indent > 255) {
            throw new InvalidArgumentException('Indent must be within 0..255.');
        }
        if (($rotation < 0 || $rotation > 180) && $rotation !== self::VERTICAL_TEXT) {
            throw new InvalidArgumentException('Rotation must be within 0..180 degrees, or 255 for vertical text.');
        }
    }

    /**
     * @internal
     */
    public function key(): string
    {
        return $this->horizontal->value . '/' . $this->vertical->value . '/' . (int) $this->wrapText
            . (int) $this->shrinkToFit . '/' . $this->indent . '/' . $this->rotation;
    }
}
