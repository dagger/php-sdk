<?php

declare(strict_types=1);

namespace Dagger\Tests\Unit\Module;

use Dagger\Client;
use Dagger\GraphQl\QueryBuilderChain;
use Dagger\Id;
use Dagger\Module\CallDispatcher;
use Dagger\Module\ValueCodec;
use Generator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('unit')]
#[CoversClass(CallDispatcher::class)]
class CallDispatcherTest extends TestCase
{
    private function dispatcher(): CallDispatcher
    {
        $client = self::createStub(Client::class);
        $client->method('loadObjectFromId')->willReturnCallback(
            static fn(string $class, Id $id) => new $class($client, new QueryBuilderChain()),
        );

        return new CallDispatcher(ValueCodec::forClient($client));
    }

    #[Test, DataProvider('provideCalls')]
    public function itCallsFunctions(string $expected, string $function, string $arguments): void
    {
        self::assertSame($expected, $this->dispatcher()->call('Main', '{"greeting":"hi"}', $function, $arguments));
    }

    /** @return Generator<string, array{string, string, string}> */
    public static function provideCalls(): Generator
    {
        yield 'state reaches the function' => ['"hi"', 'greet', '{}'];
        yield 'required argument' => ['"x"', 'required', '{"value":"x"}'];
        yield 'omitted nullable without a default is null' => ['"was null"', 'nullable', '{}'];
        yield 'given null without a default' => ['"was null"', 'nullable', '{"value":null}'];
        yield 'omitted takes the declared default' => ['"fallback"', 'defaulted', '{}'];
        yield 'given null overrides the declared default' => ['"was null"', 'defaulted', '{"value":null}'];
        yield 'given value overrides the declared default' => ['"x"', 'defaulted', '{"value":"x"}'];
        yield 'omitted scalars take their defaults' => ['"3 0.5 on"', 'count', '{}'];
        yield 'given falsy scalars are kept' => ['"0 0.0 off"', 'count', '{"number":0,"ratio":0,"on":false}'];
        yield 'nested and comma-holding lists' => [
            '[["a,b"],[],["c"]]',
            'rows',
            '{"rows":[["a,b"],[],["c"]]}',
        ];
        yield 'empty list' => ['[]', 'rows', '{"rows":[]}'];
        yield 'a list with gaps stays a list' => ['["one","three"]', 'gaps', '{}'];
        yield 'void' => ['null', 'nothing', '{}'];
        yield 'scalars' => ['"linux\/arm64 {\"a\":1}"', 'scalars', '{"platform":"linux/arm64","json":"{\"a\":1}"}'];
    }

    #[Test]
    public function itConstructsWithDefaults(): void
    {
        self::assertSame(
            ['greeting' => 'hello', 'modes' => [], 'source' => null, 'helper' => null],
            json_decode($this->dispatcher()->call('Main', null, '', '{}'), true),
        );
    }

    #[Test]
    public function itConstructsWithArguments(): void
    {
        self::assertSame(
            ['greeting' => 'hey', 'modes' => ['Slow', 'Fast']],
            array_intersect_key(
                json_decode(
                    $this->dispatcher()->call('Main', null, '', '{"greeting":"hey","modes":["Slow","Fast"]}'),
                    true,
                ),
                ['greeting' => 0, 'modes' => 0],
            ),
        );
    }

    #[Test]
    public function itRestoresEnumsAndListsFromState(): void
    {
        self::assertSame(
            '"Slow"',
            $this->dispatcher()->call('Main', '{"greeting":"hi","modes":["Slow","Fast"]}', 'firstMode', '{}'),
        );
    }

    #[Test]
    public function itReturnsNullForAnEmptyOptional(): void
    {
        self::assertSame('null', $this->dispatcher()->call('Main', '{"greeting":"hi","modes":[]}', 'firstMode', '{}'));
    }

    #[Test]
    public function itCarriesStateThroughAReturnedObject(): void
    {
        $result = $this->dispatcher()->call('Main', '{"greeting":"hi","modes":["Slow"]}', 'withMode', '{"mode":"Fast"}');

        self::assertSame(
            ['greeting' => 'hi', 'modes' => ['Slow', 'Fast']],
            array_intersect_key(json_decode($result, true), ['greeting' => 0, 'modes' => 0]),
        );
    }

    #[Test]
    public function itLoadsIdArguments(): void
    {
        $result = $this->dispatcher()->call('Main', '{"greeting":"hi"}', 'container', '{"base":"the-id"}');

        self::assertNotSame('', $result);
    }

    #[Test, DataProvider('provideErrors')]
    public function itReportsWhatWentWrong(
        string $message,
        string $type,
        ?string $receiver,
        string $function,
        ?string $arguments,
    ): void {
        self::expectExceptionMessage($message);

        $this->dispatcher()->call($type, $receiver, $function, $arguments);
    }

    /** @return Generator<string, array{string, string, ?string, string, ?string}> */
    public static function provideErrors(): Generator
    {
        yield 'unknown type' => ['No class DaggerModule\\Gone exists', 'Gone', null, '', '{}'];
        yield 'unknown function' => ['has no function gone', 'Main', '{}', 'gone', '{}'];
        yield 'not a dagger function' => ['not considered a dagger function', 'Main', '{}', 'notExposed', '{}'];
        yield 'missing argument' => ['missing the required argument value', 'Main', '{}', 'required', '{}'];
        yield 'unknown argument' => ['has no argument gone', 'Main', '{}', 'greet', '{"gone":1}'];
        yield 'bad type' => ['int is not a value of the type string', 'Main', '{}', 'required', '{"value":1}'];
        yield 'null for a required argument' => ['non-nullable type string', 'Main', '{}', 'required', '{"value":null}'];
        yield 'invalid arguments' => ['fnArgs is not valid JSON', 'Main', '{}', 'greet', '{'];
        yield 'invalid state' => ['receiverValue is not valid JSON', 'Main', '{', 'greet', '{}'];
        yield 'arguments that are not an object' => ['fnArgs is not a JSON object', 'Main', '{}', 'greet', '1'];
    }

    #[Test]
    public function itLetsAFunctionsOwnErrorThrough(): void
    {
        self::expectException(RuntimeException::class);
        self::expectExceptionMessage('the function failed');

        $this->dispatcher()->call('Main', '{"greeting":"hi"}', 'fail', '{}');
    }

    #[Test]
    public function itAcceptsAMissingReceiverAndArguments(): void
    {
        self::assertSame('"was null"', $this->dispatcher()->call('Main', null, 'nullable', null));
    }

    #[Test]
    public function itRefusesAStateThatIsNotAnObject(): void
    {
        self::expectException(InvalidArgumentException::class);

        $this->dispatcher()->call('Main', '"text"', 'greet', '{}');
    }
}
