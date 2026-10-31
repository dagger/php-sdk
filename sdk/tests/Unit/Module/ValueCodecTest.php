<?php

declare(strict_types=1);

namespace Dagger\Tests\Unit\Module;

use Dagger\Client;
use Dagger\Container;
use Dagger\Directory;
use Dagger\GraphQl\QueryBuilderChain;
use Dagger\Id;
use Dagger\Json;
use Dagger\Module\ValueCodec;
use Dagger\Platform;
use Dagger\ValueObject\ListOfType;
use Dagger\ValueObject\Type;
use DaggerModule\Helper;
use DaggerModule\Main;
use DaggerModule\Mode;
use Generator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[CoversClass(ValueCodec::class)]
class ValueCodecTest extends TestCase
{
    private function codec(): ValueCodec
    {
        $client = self::createStub(Client::class);
        $client->method('loadObjectFromId')->willReturnCallback(
            static function (string $class, Id $id) use ($client) {
                $object = new $class($client, new QueryBuilderChain());
                self::assertSame('the-id', (string) $id);

                return $object;
            },
        );

        return ValueCodec::forClient($client);
    }

    #[Test, DataProvider('provideDecoded')]
    public function itDecodes(mixed $expected, mixed $value, ListOfType|Type $type): void
    {
        self::assertEquals($expected, $this->codec()->decode($value, $type));
    }

    /** @return Generator<string, array{mixed, mixed, ListOfType|Type}> */
    public static function provideDecoded(): Generator
    {
        $string = new Type('string');

        yield 'bool' => [false, false, new Type('bool')];
        yield 'int' => [0, 0, new Type('int')];
        yield 'float' => [1.5, 1.5, new Type('float')];
        yield 'float from a whole number' => [2.0, 2, new Type('float')];
        yield 'empty string' => ['', '', $string];
        yield 'nullable' => [null, null, new Type('string', true)];
        yield 'void' => [null, null, new Type('void')];
        yield 'empty list' => [[], [], new ListOfType($string)];
        yield 'nullable list' => [null, null, new ListOfType($string, true)];
        yield 'commas stay inside elements' => [['a,b', 'c'], ['a,b', 'c'], new ListOfType($string)];
        yield 'brackets stay inside elements' => [['[x]'], ['[x]'], new ListOfType($string)];
        yield 'nested lists' => [
            [['a,b'], [], ['c']],
            [['a,b'], [], ['c']],
            new ListOfType(new ListOfType($string)),
        ];
        yield 'null element' => [['a', null], ['a', null], new ListOfType(new Type('string', true))];
        yield 'scalar' => [new Platform('linux/amd64'), 'linux/amd64', new Type(Platform::class)];
        yield 'json scalar as text' => [new Json('{"a":1}'), '{"a":1}', new Type(Json::class)];
        yield 'json scalar as a value' => [new Json('{"a":1}'), ['a' => 1], new Type(Json::class)];
        yield 'enum by case name' => [Mode::Fast, 'Fast', new Type(Mode::class)];
        yield 'list of enums' => [
            [Mode::Slow, Mode::Fast],
            ['Slow', 'Fast'],
            new ListOfType(new Type(Mode::class)),
        ];
    }

    #[Test, DataProvider('provideRefused')]
    public function itRefuses(mixed $value, ListOfType|Type $type, string $message): void
    {
        self::expectException(\Throwable::class);
        self::expectExceptionMessage($message);

        $this->codec()->decode($value, $type);
    }

    /** @return Generator<string, array{mixed, ListOfType|Type, string}> */
    public static function provideRefused(): Generator
    {
        yield 'null for a non-nullable' => [null, new Type('string'), 'non-nullable type string'];
        yield 'null in a non-nullable list' => [
            [null],
            new ListOfType(new Type('string')),
            'non-nullable type string',
        ];
        yield 'string for an int' => ['1', new Type('int'), 'string is not a value of the type int'];
        yield 'int for a string' => [1, new Type('string'), 'int is not a value of the type string'];
        yield 'object for a list' => [
            ['a' => 1],
            new ListOfType(new Type('string')),
            'array is not a value of the type [string]',
        ];
        yield 'unknown enum case' => ['Medium', new Type(Mode::class), 'available cases are'];
        yield 'empty enum case' => ['', new Type(Mode::class), 'is not a value of the type'];
        yield 'int for a scalar' => [1, new Type(Platform::class), 'is not a value of the type'];
    }

    #[Test]
    public function itLoadsIdsThroughTheClient(): void
    {
        $decoded = $this->codec()->decode('the-id', new Type(Container::class));

        self::assertInstanceOf(Container::class, $decoded);
    }

    #[Test]
    public function itLoadsListsOfIds(): void
    {
        $decoded = $this->codec()->decode(['the-id', 'the-id'], new ListOfType(new Type(Directory::class)));

        self::assertContainsOnlyInstancesOf(Directory::class, $decoded);
        self::assertCount(2, $decoded);
    }

    #[Test]
    public function itRestoresObjectStateWithLists(): void
    {
        $decoded = $this->codec()->decode(
            ['greeting' => 'hi', 'modes' => ['Slow'], 'source' => 'the-id'],
            new Type(Main::class),
        );

        self::assertInstanceOf(Main::class, $decoded);
        self::assertSame('hi', $decoded->greeting);
        self::assertSame([Mode::Slow], $decoded->modes);
        self::assertInstanceOf(Directory::class, $decoded->source);
    }

    #[Test]
    public function itRestoresListsInNestedObjects(): void
    {
        $decoded = $this->codec()->decode(
            ['greeting' => 'hi', 'helper' => ['label' => 'nested', 'modes' => ['Fast']]],
            new Type(Main::class),
        );

        self::assertSame('nested', $decoded->helper->label);
        self::assertSame([Mode::Fast], $decoded->helper->modes);
    }

    #[Test]
    public function itRestoresANullNestedObject(): void
    {
        $decoded = $this->codec()->decode(['greeting' => 'hi', 'helper' => null], new Type(Main::class));

        self::assertNull($decoded->helper);
    }

    #[Test]
    public function itRestoresObjectsWithoutRunningTheirConstructor(): void
    {
        $decoded = $this->codec()->decode(['label' => 'from state'], new Type(Helper::class));

        self::assertSame('from state', $decoded->label);
    }

    #[Test, DataProvider('provideEncoded')]
    public function itEncodes(string $expected, mixed $value, ListOfType|Type|null $type = null): void
    {
        self::assertSame($expected, $this->codec()->encode($value, $type));
    }

    /** @return Generator<string, array{0: string, 1: mixed, 2?: ListOfType|Type}> */
    public static function provideEncoded(): Generator
    {
        yield 'null' => ['null', null];
        yield 'string' => ['"a,b"', 'a,b'];
        yield 'empty list' => ['[]', [], new ListOfType(new Type('string'))];
        yield 'nested lists' => [
            '[["a"],[],["b"]]',
            [['a'], [], ['b']],
            new ListOfType(new ListOfType(new Type('string'))),
        ];
        yield 'list with gaps' => ['["a","b"]', [1 => 'a', 3 => 'b'], new ListOfType(new Type('string'))];
        yield 'enum by case name' => ['"Fast"', Mode::Fast, new Type(Mode::class)];
        yield 'list of enums' => ['["Fast","Slow"]', [Mode::Fast, Mode::Slow], new ListOfType(new Type(Mode::class))];
        yield 'scalar' => ['"linux\/amd64"', new Platform('linux/amd64'), new Type(Platform::class)];
    }

    #[Test]
    public function itEncodesIds(): void
    {
        $container = self::createStub(Container::class);
        $container->method('id')->willReturn(new Id('the-id'));

        self::assertSame('"the-id"', $this->codec()->encode($container, new Type(Container::class)));
    }

    #[Test]
    public function itEncodesObjectState(): void
    {
        $main = new Main('hi', [Mode::Fast]);

        self::assertSame(
            ['greeting' => 'hi', 'modes' => ['Fast'], 'source' => null, 'helper' => null],
            json_decode($this->codec()->encode($main, new Type(Main::class)), true),
        );
    }

    #[Test]
    public function itRefusesCustomInterfaces(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('cannot decode custom interfaces');

        $this->codec()->decode('x', new Type(\Stringable::class));
    }
}
