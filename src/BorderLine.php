<?php

declare(strict_types=1);

namespace Vensas\Hypaxel;

/**
 * One edge of a border.
 */
final readonly class BorderLine
{
    public ?Color $color;

    public function __construct(public BorderStyle $style = BorderStyle::None, Color|string|null $color = null)
    {
        $this->color = $color === null ? null : Color::from($color);
    }

    /**
     * @internal
     */
    public function key(): string
    {
        return $this->style->value . '/' . ($this->color === null ? '' : $this->color->argb);
    }
}
