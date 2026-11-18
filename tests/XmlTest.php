<?php

declare(strict_types=1);

namespace Vensas\Hypaxel\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vensas\Hypaxel\Internal\Xml;

final class XmlTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function escapes(): iterable
    {
        yield 'plain unicode' => ['plain ünïcødé 😀', 'plain ünïcødé 😀'];
        yield 'xml specials' => ["a&b<c>d\"e'f", "a&amp;b&lt;c&gt;d&quot;e'f"];
        yield 'allowed whitespace' => ["tab\tnl\n", "tab\tnl\n"];
        yield 'carriage return' => ["a\r\nb\r", "a_x000D_\nb_x000D_"];
        yield 'control characters' => ["\u{1}x\u{1f}", '_x0001_x_x001F_'];
        yield 'nul byte' => ["a\0b", 'a_x0000_b'];
        yield 'literal excel escape' => ['a_x0041_b snake_case', 'a_x005F_x0041_b snake_case'];
        yield 'non characters' => ["\u{FFFE}\u{FFFF}\u{FF01}", "_xFFFE__xFFFF_\u{FF01}"];
        yield 'invalid utf-8' => ["caf\xE9 <", "caf\u{FFFD} &lt;"];
        yield 'encoded surrogate' => ["a\xED\xA0\x80b", "a\u{FFFD}b"];
    }

    #[DataProvider('escapes')]
    public function testEscape(string $input, string $expected): void
    {
        self::assertSame($expected, Xml::escape($input));
    }

    public function testTextElementPreservesOuterWhitespace(): void
    {
        self::assertSame('<t xml:space="preserve"> a</t>', Xml::textElement(' a'));
        self::assertSame('<t xml:space="preserve">a&amp;' . "\n" . '</t>', Xml::textElement("a&\n"));
        self::assertSame('<t>a&lt;b</t>', Xml::textElement('a<b'));
        self::assertSame('<t></t>', Xml::textElement(''));
    }

    public function testNeedsCareClassifiesText(): void
    {
        self::assertSame(0, \preg_match(Xml::NEEDS_CARE, 'Plain text with spaces, ünïcødé and snake_case'));
        self::assertSame(0, \preg_match(Xml::NEEDS_CARE, ''));
        foreach (['a&b', ' lead', "trail\n", "trail\n\n", '_x0041_', "\x02", "\u{FFFF}"] as $text) {
            self::assertSame(1, \preg_match(Xml::NEEDS_CARE, $text), \bin2hex($text));
        }
        self::assertFalse(@\preg_match(Xml::NEEDS_CARE, "\xC3"));
    }

    public function testUtf16Length(): void
    {
        self::assertSame(0, Xml::utf16Length(''));
        self::assertSame(5, Xml::utf16Length('hello'));
        self::assertSame(4, Xml::utf16Length('äöü€'));
        self::assertSame(2, Xml::utf16Length('😀'));
        self::assertSame(5, Xml::utf16Length('a😀b€'));
    }

    public function testColumnNamesAndReferences(): void
    {
        self::assertSame('A', Xml::columnName(1));
        self::assertSame('Z', Xml::columnName(26));
        self::assertSame('AA', Xml::columnName(27));
        self::assertSame('AZ', Xml::columnName(52));
        self::assertSame('ZZ', Xml::columnName(702));
        self::assertSame('AAA', Xml::columnName(703));
        self::assertSame('XFD', Xml::columnName(16384));
        self::assertSame('B7', Xml::cellRef(7, 2));
        self::assertSame('A1:XFD1048576', Xml::range(1, 1, 1_048_576, 16_384));
    }

    public function testFloatsRoundTrip(): void
    {
        self::assertSame('1', Xml::float(1.0));
        self::assertSame('0', Xml::float(-0.0));
        self::assertSame('-0.1', Xml::float(-0.1));
        self::assertSame('1.0e+300', Xml::float(1e300));
        self::assertSame('9007199254740992', Xml::float(9_007_199_254_740_992.0));
        foreach ([0.1, 1 / 3, 1 / 7, 2.5e-10, 1.7976931348623157e308, 5e-324, 123456789.12345679, 1e22] as $value) {
            self::assertSame($value, (float) Xml::float($value));
        }
    }

    public function testFloatsIgnoreALoweredSerializePrecision(): void
    {
        $previous = \ini_set('serialize_precision', '5');
        try {
            self::assertSame(1 / 3, (float) Xml::float(1 / 3));
        } finally {
            \ini_set('serialize_precision', $previous === false ? '-1' : $previous);
        }
    }
}
