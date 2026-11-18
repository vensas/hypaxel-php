<?php

declare(strict_types=1);

namespace Vensas\Hypaxel\Internal;

use Vensas\Hypaxel\Alignment;
use Vensas\Hypaxel\Border;
use Vensas\Hypaxel\BorderLine;
use Vensas\Hypaxel\BorderStyle;
use Vensas\Hypaxel\Color;
use Vensas\Hypaxel\Font;
use Vensas\Hypaxel\HorizontalAlignment;
use Vensas\Hypaxel\Style;
use Vensas\Hypaxel\VerticalAlignment;

/**
 * De-duplicates fonts, fills, borders, number formats and their combinations (`cellXfs`).
 *
 * @internal
 */
final class StyleRegistry
{
    public const KIND_DATE = 0;
    public const KIND_DATETIME = 1;
    public const KIND_DURATION = 2;

    /** Built-in formats: locale short date, locale short date with time, `[h]:mm:ss`. */
    private const KIND_FORMATS = [self::KIND_DATE => 14, self::KIND_DATETIME => 22, self::KIND_DURATION => 46];

    private const BUILTIN_FORMATS = [
        'General' => 0,
        '0' => 1,
        '0.00' => 2,
        '#,##0' => 3,
        '#,##0.00' => 4,
        '0%' => 9,
        '0.00%' => 10,
        '0.00E+00' => 11,
        '@' => 49,
    ];

    private const FIRST_CUSTOM_FORMAT = 164;

    /** @var list<Font> */
    private array $fonts;

    /** @var array<string, int> */
    private array $fontIds;

    /** @var list<Color> without the two mandatory fills */
    private array $fills = [];

    /** @var array<int, int> */
    private array $fillIds = [];

    /** @var list<Border> without the default border */
    private array $borders = [];

    /** @var array<string, int> */
    private array $borderIds = [];

    /** @var array<array-key, int> format code => id */
    private array $numberFormatIds = [];

    /** @var list<array{numberFormat: int, font: int, fill: int, border: int, alignment: ?Alignment}> */
    private array $xfs = [['numberFormat' => 0, 'font' => 0, 'fill' => 0, 'border' => 0, 'alignment' => null]];

    /** @var array<string, int> */
    private array $xfIds = ['0/0/0/0/' => 0];

    /** @var array<int, int> style index * 4 + kind => style index */
    private array $variants = [];

    public function __construct()
    {
        $default = new Font();
        $this->fonts = [$default];
        $this->fontIds = [$default->key() => 0];
    }

    public function count(): int
    {
        return \count($this->xfs);
    }

    public function add(Style $style): int
    {
        $numberFormat = $style->numberFormat === null ? 0 : $this->numberFormatId($style->numberFormat);

        $font = 0;
        if ($style->font !== null) {
            $key = $style->font->key();
            $font = $this->fontIds[$key] ?? null;
            if ($font === null) {
                $font = $this->fontIds[$key] = \count($this->fonts);
                $this->fonts[] = $style->font;
            }
        }

        $fill = 0;
        if ($style->fill !== null) {
            $key = $style->fill->argb;
            $fill = $this->fillIds[$key] ?? null;
            if ($fill === null) {
                $this->fills[] = $style->fill;
                $fill = $this->fillIds[$key] = \count($this->fills) + 1;
            }
        }

        $border = 0;
        if ($style->border !== null) {
            $key = $style->border->key();
            $border = $this->borderIds[$key] ?? null;
            if ($border === null) {
                $this->borders[] = $style->border;
                $border = $this->borderIds[$key] = \count($this->borders);
            }
        }

        return $this->xfId($numberFormat, $font, $fill, $border, $style->alignment);
    }

    /**
     * A format able to display a date, time or duration: the style itself when it already has a
     * number format, otherwise a variant of it with the matching built-in format.
     */
    public function variant(int $style, int $kind): int
    {
        $key = $style * 4 + $kind;
        if (isset($this->variants[$key])) {
            return $this->variants[$key];
        }
        $xf = $this->xfs[$style] ?? null;
        if ($xf === null) {
            return $style;
        }
        $id = $xf['numberFormat'] !== 0
            ? $style
            : $this->xfId(self::KIND_FORMATS[$kind], $xf['font'], $xf['fill'], $xf['border'], $xf['alignment']);

        return $this->variants[$key] = $id;
    }

    /**
     * What a style's rendering width depends on: font size in points, bold, and the number format
     * as a built-in id or a custom code.
     *
     * @return array{float, bool, int|string}
     */
    public function measureInfo(int $style): array
    {
        $xf = $this->xfs[$style] ?? $this->xfs[0];
        $font = $this->fonts[$xf['font']];
        $format = $xf['numberFormat'];
        if ($format >= self::FIRST_CUSTOM_FORMAT) {
            $code = \array_search($format, $this->numberFormatIds, true);
            $format = $code === false ? 0 : (string) $code;
        }

        return [$font->size, $font->bold, $format];
    }

    public function toXml(): string
    {
        $x = Xml::DECLARATION . '<styleSheet xmlns="' . Xml::NS_MAIN . '">';

        if ($this->numberFormatIds !== []) {
            $x .= '<numFmts count="' . \count($this->numberFormatIds) . '">';
            foreach ($this->numberFormatIds as $code => $id) {
                $x .= '<numFmt numFmtId="' . $id . '" formatCode="' . Xml::escape((string) $code) . '"/>';
            }
            $x .= '</numFmts>';
        }

        $x .= '<fonts count="' . \count($this->fonts) . '">';
        foreach ($this->fonts as $font) {
            $x .= '<font>' . ($font->bold ? '<b/>' : '') . ($font->italic ? '<i/>' : '')
                . ($font->strikethrough ? '<strike/>' : '') . ($font->underline ? '<u/>' : '')
                . '<sz val="' . Xml::float(\round($font->size * 100) / 100) . '"/>'
                . ($font->color === null ? '' : self::color('color', $font->color))
                // No theme part is written, so fonts never reference theme colors or schemes.
                . '<name val="' . Xml::escape($font->name) . '"/><family val="2"/></font>';
        }

        $x .= '</fonts><fills count="' . (\count($this->fills) + 2)
            . '"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>';
        foreach ($this->fills as $color) {
            $x .= '<fill><patternFill patternType="solid">' . self::color('fgColor', $color)
                . '<bgColor indexed="64"/></patternFill></fill>';
        }

        $x .= '</fills><borders count="' . (\count($this->borders) + 1)
            . '"><border><left/><right/><top/><bottom/><diagonal/></border>';
        foreach ($this->borders as $border) {
            $x .= '<border>' . self::borderLine('left', $border->left) . self::borderLine('right', $border->right)
                . self::borderLine('top', $border->top) . self::borderLine('bottom', $border->bottom)
                . '<diagonal/></border>';
        }

        $x .= '</borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . \count($this->xfs) . '">';
        foreach ($this->xfs as $xf) {
            $x .= '<xf numFmtId="' . $xf['numberFormat'] . '" fontId="' . $xf['font'] . '" fillId="' . $xf['fill']
                . '" borderId="' . $xf['border'] . '" xfId="0"'
                . ($xf['numberFormat'] !== 0 ? ' applyNumberFormat="1"' : '')
                . ($xf['font'] !== 0 ? ' applyFont="1"' : '')
                . ($xf['fill'] !== 0 ? ' applyFill="1"' : '')
                . ($xf['border'] !== 0 ? ' applyBorder="1"' : '');
            $a = $xf['alignment'];
            if ($a === null) {
                $x .= '/>';
                continue;
            }
            $x .= ' applyAlignment="1"><alignment'
                . ($a->horizontal !== HorizontalAlignment::General ? ' horizontal="' . $a->horizontal->value . '"' : '')
                . ($a->vertical !== VerticalAlignment::Bottom ? ' vertical="' . $a->vertical->value . '"' : '')
                . ($a->rotation !== 0 ? ' textRotation="' . $a->rotation . '"' : '')
                . ($a->wrapText ? ' wrapText="1"' : '')
                . ($a->indent !== 0 ? ' indent="' . $a->indent . '"' : '')
                . ($a->shrinkToFit ? ' shrinkToFit="1"' : '')
                . '/></xf>';
        }

        return $x . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '<dxfs count="0"/><tableStyles count="0"/></styleSheet>';
    }

    private function xfId(int $numberFormat, int $font, int $fill, int $border, ?Alignment $alignment): int
    {
        $key = $numberFormat . '/' . $font . '/' . $fill . '/' . $border . '/' . ($alignment?->key() ?? '');
        $id = $this->xfIds[$key] ?? null;
        if ($id === null) {
            $id = $this->xfIds[$key] = \count($this->xfs);
            $this->xfs[] = ['numberFormat' => $numberFormat, 'font' => $font, 'fill' => $fill, 'border' => $border, 'alignment' => $alignment];
        }

        return $id;
    }

    private function numberFormatId(string $code): int
    {
        return self::BUILTIN_FORMATS[$code]
            ?? ($this->numberFormatIds[$code] ??= self::FIRST_CUSTOM_FORMAT + \count($this->numberFormatIds));
    }

    private static function color(string $element, Color $color): string
    {
        return '<' . $element . ' rgb="' . $color->toHex() . '"/>';
    }

    private static function borderLine(string $element, BorderLine $line): string
    {
        if ($line->style === BorderStyle::None) {
            return '<' . $element . '/>';
        }

        return '<' . $element . ' style="' . $line->style->value . '">'
            . ($line->color === null ? '<color indexed="64"/>' : self::color('color', $line->color))
            . '</' . $element . '>';
    }
}
