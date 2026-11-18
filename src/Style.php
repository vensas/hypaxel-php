<?php

declare(strict_types=1);

namespace Vensas\Hypaxel;

/**
 * An immutable cell format. Every modifier returns a new instance:
 *
 *     $header = Style::create()->bold()->fontColor('#FFFFFF')->fill('#305496')->align(HorizontalAlignment::Center);
 *     $money = Style::create()->numberFormat('#,##0.00');
 *
 * Register it with {@see Writer::addStyle()} to get the handle that cells are written with.
 */
final readonly class Style
{
    public ?Color $fill;

    /**
     * @param string|null       $numberFormat Excel format code, e.g. `0.00`, `#,##0`, `yyyy-mm-dd`, `@`
     * @param Color|string|null $fill         solid background color
     */
    public function __construct(
        public ?string $numberFormat = null,
        public ?Font $font = null,
        Color|string|null $fill = null,
        public ?Border $border = null,
        public ?Alignment $alignment = null,
    ) {
        $this->fill = $fill === null ? null : Color::from($fill);
    }

    public static function create(): self
    {
        return new self();
    }

    public function numberFormat(?string $code): self
    {
        return new self($code, $this->font, $this->fill, $this->border, $this->alignment);
    }

    public function font(?Font $font): self
    {
        return new self($this->numberFormat, $font, $this->fill, $this->border, $this->alignment);
    }

    public function bold(bool $bold = true): self
    {
        $f = $this->font ?? new Font();

        return $this->font(new Font($f->name, $f->size, $bold, $f->italic, $f->underline, $f->strikethrough, $f->color));
    }

    public function italic(bool $italic = true): self
    {
        $f = $this->font ?? new Font();

        return $this->font(new Font($f->name, $f->size, $f->bold, $italic, $f->underline, $f->strikethrough, $f->color));
    }

    public function underline(bool $underline = true): self
    {
        $f = $this->font ?? new Font();

        return $this->font(new Font($f->name, $f->size, $f->bold, $f->italic, $underline, $f->strikethrough, $f->color));
    }

    public function strikethrough(bool $strikethrough = true): self
    {
        $f = $this->font ?? new Font();

        return $this->font(new Font($f->name, $f->size, $f->bold, $f->italic, $f->underline, $strikethrough, $f->color));
    }

    public function fontColor(Color|string|null $color): self
    {
        $f = $this->font ?? new Font();

        return $this->font(new Font($f->name, $f->size, $f->bold, $f->italic, $f->underline, $f->strikethrough, $color));
    }

    public function fontSize(float $points): self
    {
        $f = $this->font ?? new Font();

        return $this->font(new Font($f->name, $points, $f->bold, $f->italic, $f->underline, $f->strikethrough, $f->color));
    }

    public function fontName(string $name): self
    {
        $f = $this->font ?? new Font();

        return $this->font(new Font($name, $f->size, $f->bold, $f->italic, $f->underline, $f->strikethrough, $f->color));
    }

    public function fill(Color|string|null $color): self
    {
        return new self($this->numberFormat, $this->font, $color, $this->border, $this->alignment);
    }

    public function border(Border|BorderStyle|null $border, Color|string|null $color = null): self
    {
        if ($border instanceof BorderStyle) {
            $border = Border::all($border, $color);
        }

        return new self($this->numberFormat, $this->font, $this->fill, $border, $this->alignment);
    }

    public function alignment(?Alignment $alignment): self
    {
        return new self($this->numberFormat, $this->font, $this->fill, $this->border, $alignment);
    }

    public function align(HorizontalAlignment $horizontal): self
    {
        $a = $this->alignment ?? new Alignment();

        return $this->alignment(new Alignment($horizontal, $a->vertical, $a->wrapText, $a->shrinkToFit, $a->indent, $a->rotation));
    }

    public function valign(VerticalAlignment $vertical): self
    {
        $a = $this->alignment ?? new Alignment();

        return $this->alignment(new Alignment($a->horizontal, $vertical, $a->wrapText, $a->shrinkToFit, $a->indent, $a->rotation));
    }

    public function wrap(bool $wrap = true): self
    {
        $a = $this->alignment ?? new Alignment();

        return $this->alignment(new Alignment($a->horizontal, $a->vertical, $wrap, $a->shrinkToFit, $a->indent, $a->rotation));
    }

    public function shrinkToFit(bool $shrink = true): self
    {
        $a = $this->alignment ?? new Alignment();

        return $this->alignment(new Alignment($a->horizontal, $a->vertical, $a->wrapText, $shrink, $a->indent, $a->rotation));
    }

    public function indent(int $levels): self
    {
        $a = $this->alignment ?? new Alignment();

        return $this->alignment(new Alignment($a->horizontal, $a->vertical, $a->wrapText, $a->shrinkToFit, $levels, $a->rotation));
    }

    public function rotation(int $degrees): self
    {
        $a = $this->alignment ?? new Alignment();

        return $this->alignment(new Alignment($a->horizontal, $a->vertical, $a->wrapText, $a->shrinkToFit, $a->indent, $degrees));
    }
}
