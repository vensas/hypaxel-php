<?php

declare(strict_types=1);

namespace Vensas\Hypaxel;

final readonly class Border
{
    public BorderLine $left;
    public BorderLine $right;
    public BorderLine $top;
    public BorderLine $bottom;

    public function __construct(
        BorderLine|BorderStyle|null $left = null,
        BorderLine|BorderStyle|null $right = null,
        BorderLine|BorderStyle|null $top = null,
        BorderLine|BorderStyle|null $bottom = null,
    ) {
        $this->left = self::line($left);
        $this->right = self::line($right);
        $this->top = self::line($top);
        $this->bottom = self::line($bottom);
    }

    /**
     * The same line on all four edges.
     */
    public static function all(BorderStyle $style = BorderStyle::Thin, Color|string|null $color = null): self
    {
        $line = new BorderLine($style, $color);

        return new self($line, $line, $line, $line);
    }

    /**
     * @internal
     */
    public function key(): string
    {
        return $this->left->key() . '|' . $this->right->key() . '|' . $this->top->key() . '|' . $this->bottom->key();
    }

    private static function line(BorderLine|BorderStyle|null $line): BorderLine
    {
        if ($line instanceof BorderLine) {
            return $line;
        }

        return new BorderLine($line ?? BorderStyle::None);
    }
}
