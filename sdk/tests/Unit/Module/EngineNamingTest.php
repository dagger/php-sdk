<?php

declare(strict_types=1);

namespace Dagger\Tests\Unit\Module;

use Dagger\Module\EngineNaming;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[CoversClass(EngineNaming::class)]
class EngineNamingTest extends TestCase
{
    #[Test, DataProvider('provideEngineNames')]
    public function itNamesEnumMembersAsTheEngineDoes(string $case, string $expected): void
    {
        self::assertSame($expected, EngineNaming::enumMemberName($case));
    }

    /**
     * Each expectation is the output of gqlEnumMemberName at dagger/dagger
     * a7a2e64e853be92728f05aad9dc07bebe63c7448 with iancoleman/strcase v0.3.0.
     *
     * @return Generator<string, array{string, string}>
     */
    public static function provideEngineNames(): Generator
    {
        yield 'Low' => ['Low', 'LOW'];
        yield 'LOW' => ['LOW', 'LOW'];
        yield 'FooBar' => ['FooBar', 'FOO_BAR'];
        yield 'FOO_BAR' => ['FOO_BAR', 'FOO_BAR'];
        yield 'Foobar' => ['Foobar', 'FOOBAR'];
        yield 'ReadOnly' => ['ReadOnly', 'READ_ONLY'];
        yield 'READONLY' => ['READONLY', 'READONLY'];
        yield 'Foo1' => ['Foo1', 'FOO_1'];
        yield 'FOO1' => ['FOO1', 'FOO1'];
        yield 'JSONData' => ['JSONData', 'JSON_DATA'];
        yield 'Foo_Bar' => ['Foo_Bar', 'FOO_BAR'];
        yield 'Foo__Bar' => ['Foo__Bar', 'FOO__BAR'];
        yield '_Low' => ['_Low', '_LOW'];
        yield '__X' => ['__X', '__X'];
        yield 'A1' => ['A1', 'A1'];
        yield 'A_1' => ['A_1', 'A_1'];
        yield 'the fast path comes before trimming' => ["\u{00A0}A1", 'A_1'];
        yield 'spaces at both ends' => ["\u{2003}\u{0085}Low\u{00A0}\u{3000}", 'LOW'];
        yield 'a name of spaces only' => ["\u{00A0}\u{3000}", ''];
        yield 'a digit after trimming' => ["\u{00A0}1a", '1_A'];
        yield 'a space inside a name' => ["Lo\u{00A0}w", "LO\u{00A0}W"];
        yield 'a letter from 0x80' => ["\u{00E9}Low", "\u{00E9}LOW"];
        yield 'not a space: U+200B' => ["\u{200B}LOW", "\u{200B}LOW"];
        yield 'not a space: U+180E' => ["\u{180E}LOW", "\u{180E}LOW"];
        yield 'not a space: U+FEFF' => ["LOW\u{FEFF}", "LOW\u{FEFF}"];
        yield 'half of a no-break space' => ["\xC2LOW\xA0", "\xC2LOW\xA0"];
    }

    #[Test, DataProvider('provideUnicodeSpaces')]
    public function itTrimsWhatGoCountsAsSpace(string $space): void
    {
        self::assertSame('LOW', EngineNaming::enumMemberName($space . 'Low'));
        self::assertSame('LOW', EngineNaming::enumMemberName('Low' . $space));
        self::assertSame('A_1', EngineNaming::enumMemberName($space . 'A1' . $space));
    }

    /**
     * The non-ASCII runes of Go's unicode.IsSpace, spelled as code points to be
     * checked independently of the byte pattern they test.
     *
     * @return Generator<string, array{string}>
     */
    public static function provideUnicodeSpaces(): Generator
    {
        yield 'U+0085' => ["\u{0085}"];
        yield 'U+00A0' => ["\u{00A0}"];
        yield 'U+1680' => ["\u{1680}"];
        yield 'U+2000' => ["\u{2000}"];
        yield 'U+2001' => ["\u{2001}"];
        yield 'U+2002' => ["\u{2002}"];
        yield 'U+2003' => ["\u{2003}"];
        yield 'U+2004' => ["\u{2004}"];
        yield 'U+2005' => ["\u{2005}"];
        yield 'U+2006' => ["\u{2006}"];
        yield 'U+2007' => ["\u{2007}"];
        yield 'U+2008' => ["\u{2008}"];
        yield 'U+2009' => ["\u{2009}"];
        yield 'U+200A' => ["\u{200A}"];
        yield 'U+2028' => ["\u{2028}"];
        yield 'U+2029' => ["\u{2029}"];
        yield 'U+202F' => ["\u{202F}"];
        yield 'U+205F' => ["\u{205F}"];
        yield 'U+3000' => ["\u{3000}"];
    }
}
