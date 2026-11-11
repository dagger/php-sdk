<?php

declare(strict_types=1);

namespace Dagger\Tests\Unit\ValueObject;

use Dagger\Agent;
use Dagger\Attribute\DaggerObject;
use Dagger\Changeset;
use Dagger\Container;
use Dagger\Exception\RegistrationError\Invalid;
use Dagger\Exception\RegistrationError\MissingAttribute;
use Dagger\File;
use Dagger\Json;
use Dagger\LLM;
use Dagger\Service;
use Dagger\Tests\Unit\Fixture\DaggerObjectWithDaggerFunctions;
use Dagger\ValueObject\Argument;
use Dagger\ValueObject\DaggerFunction;
use Dagger\ValueObject\Type;
use Dagger\Workspace;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

#[Group('unit')]
#[CoversClass(DaggerFunction::class)]
class DaggerFunctionTest extends TestCase
{
    #[Test]
    #[DataProvider('provideInvalidDaggerFunctions')]
    public function itCannotTakeInvalidDaggerFunctions(
        ReflectionMethod $reflection,
        \RuntimeException $expected,
    ): void {
        self::expectExceptionObject($expected);

        DaggerFunction::fromReflection($reflection);
    }

    #[Test]
    public function itLetsAGeneratorRequireAWorkspace(): void
    {
        $method = (new ReflectionClass(new #[DaggerObject] class () {
            #[\Dagger\Attribute\DaggerFunction]
            #[\Dagger\Attribute\Generate]
            public function generate(Workspace $workspace): Changeset {return new Changeset();}
        }))->getMethod('generate');

        self::assertTrue(DaggerFunction::fromReflection($method)->withGenerator);
    }

    #[Test]
    public function itLetsAnUpFunctionRequireAWorkspace(): void
    {
        $method = (new ReflectionClass(new #[DaggerObject] class () {
            #[\Dagger\Attribute\DaggerFunction]
            #[\Dagger\Attribute\Up]
            public function serve(Workspace $workspace): Service {return new Service();}
        }))->getMethod('serve');

        self::assertTrue(DaggerFunction::fromReflection($method)->withUp);
    }

    #[Test]
    public function itLetsAnAgentRequireItsBaseAWorkspaceAndAnAgent(): void
    {
        $method = (new ReflectionClass(new #[DaggerObject] class () {
            #[\Dagger\Attribute\DaggerFunction]
            #[\Dagger\Attribute\Agent]
            public function assist(LLM $base, Workspace $workspace, Agent $owner, ?string $hint = null): LLM {return $base;}
        }))->getMethod('assist');

        self::assertTrue(DaggerFunction::fromReflection($method)->withAgent);
    }

    #[Test]
    #[DataProvider('provideNamesThatMayBeConstructors')]
    public function itMayBeAConstructor(
        bool $expected,
        string $name,
    ): void {
        $sut = new DaggerFunction($name, null, [], new Type('void'));

        self::assertSame($expected, $sut->isConstructor());
    }

    #[Test, DataProvider('provideReflectionMethods')]
    public function ItBuildsFromReflectionMethod(
        DaggerFunction $expected,
        ReflectionMethod $reflectionMethod,
    ): void {
        $actual = DaggerFunction::fromReflection($reflectionMethod);

        self::assertEquals($expected, $actual);
    }

    /** @return Generator<array{0: ReflectionMethod, 1: RuntimeException}> */
    public static function provideInvalidDaggerFunctions(): Generator
    {
        yield 'DaggerFunction attribute missing' => [
            (new ReflectionClass(new #[DaggerObject] class () {
                public function noAttribute(): string {return 'hello world';}
            }))->getMethod('noAttribute'),
            new RuntimeException(sprintf(
                'Method "noAttribute" is not considered a dagger function without the %s attribute',
                \Dagger\Attribute\DaggerFunction::class,
            )),
        ];

        yield 'return typehint missing' => [
            (new ReflectionClass(new #[DaggerObject] class () {
                #[\Dagger\Attribute\DaggerFunction]
                public function noReturnType() {return 'hello world';}
            }))->getMethod('noReturnType'),
            new RuntimeException(
                'DaggerFunction "noReturnType" cannot be supported without a return type'
            ),
        ];

        yield 'missing attribute for returning arrays' => [
            (new ReflectionClass(new #[DaggerObject] class () {
                #[\Dagger\Attribute\DaggerFunction]
                public function returnsArrayWithoutAttribute(): array {return ['hello', 'world'];}
            }))->getMethod('returnsArrayWithoutAttribute'),
            MissingAttribute::returnsListOfType('returnsArrayWithoutAttribute'),
        ];

        yield 'check requiring a string' => [
            (new ReflectionClass(new #[DaggerObject] class () {
                #[\Dagger\Attribute\DaggerFunction]
                #[\Dagger\Attribute\Check]
                public function lint(string $path): void {}
            }))->getMethod('lint'),
            Invalid::checkTakesNoArgs('lint', 'path'),
        ];

        yield 'check requiring a list' => [
            (new ReflectionClass(new #[DaggerObject] class () {
                #[\Dagger\Attribute\DaggerFunction]
                #[\Dagger\Attribute\Check]
                public function lint(#[\Dagger\Attribute\ListOfType('string')] array $paths): void {}
            }))->getMethod('lint'),
            Invalid::checkTakesNoArgs('lint', 'paths'),
        ];

        yield 'generator that requires an argument' => [
            (new ReflectionClass(new #[DaggerObject] class () {
                #[\Dagger\Attribute\DaggerFunction]
                #[\Dagger\Attribute\Generate]
                public function generate(string $name): Changeset {return new Changeset();}
            }))->getMethod('generate'),
            Invalid::generatorRequiresOnlyWorkspace('generate', 'name'),
        ];

        yield 'generator that may return null' => [
            (new ReflectionClass(new #[DaggerObject] class () {
                #[\Dagger\Attribute\DaggerFunction]
                #[\Dagger\Attribute\Generate]
                public function generate(): ?Changeset {return null;}
            }))->getMethod('generate'),
            Invalid::generatorReturnsChangeset('generate', Changeset::class),
        ];

        yield 'generator that requires a list' => [
            (new ReflectionClass(new #[DaggerObject] class () {
                #[\Dagger\Attribute\DaggerFunction]
                #[\Dagger\Attribute\Generate]
                public function generate(#[\Dagger\Attribute\ListOfType('string')] array $names): Changeset {return new Changeset();}
            }))->getMethod('generate'),
            Invalid::generatorRequiresOnlyWorkspace('generate', 'names'),
        ];

        yield 'generator that returns a list' => [
            (new ReflectionClass(new #[DaggerObject] class () {
                #[\Dagger\Attribute\DaggerFunction]
                #[\Dagger\Attribute\Generate]
                #[\Dagger\Attribute\ReturnsListOfType('string')]
                public function generate(): array {return [];}
            }))->getMethod('generate'),
            Invalid::generatorReturnsChangeset('generate', 'array'),
        ];

        yield 'up function that requires an argument' => [
            (new ReflectionClass(new #[DaggerObject] class () {
                #[\Dagger\Attribute\DaggerFunction]
                #[\Dagger\Attribute\Up]
                public function serve(string $name): Service {return new Service();}
            }))->getMethod('serve'),
            Invalid::upRequiresOnlyWorkspace('serve', 'name'),
        ];

        yield 'up function that may return null' => [
            (new ReflectionClass(new #[DaggerObject] class () {
                #[\Dagger\Attribute\DaggerFunction]
                #[\Dagger\Attribute\Up]
                public function serve(): ?Service {return null;}
            }))->getMethod('serve'),
            Invalid::upReturnsService('serve', Service::class),
        ];

        yield 'up function that requires a list' => [
            (new ReflectionClass(new #[DaggerObject] class () {
                #[\Dagger\Attribute\DaggerFunction]
                #[\Dagger\Attribute\Up]
                public function serve(#[\Dagger\Attribute\ListOfType('string')] array $names): Service {return new Service();}
            }))->getMethod('serve'),
            Invalid::upRequiresOnlyWorkspace('serve', 'names'),
        ];

        yield 'up function that returns a list' => [
            (new ReflectionClass(new #[DaggerObject] class () {
                #[\Dagger\Attribute\DaggerFunction]
                #[\Dagger\Attribute\Up]
                #[\Dagger\Attribute\ReturnsListOfType('string')]
                public function serve(): array {return [];}
            }))->getMethod('serve'),
            Invalid::upReturnsService('serve', 'array'),
        ];

        yield 'agent that requires an argument' => [
            (new ReflectionClass(new #[DaggerObject] class () {
                #[\Dagger\Attribute\DaggerFunction]
                #[\Dagger\Attribute\Agent]
                public function assist(LLM $base, string $name): LLM {return $base;}
            }))->getMethod('assist'),
            Invalid::agentRequiresArgument('assist', 'name'),
        ];

        yield 'agent that requires a list' => [
            (new ReflectionClass(new #[DaggerObject] class () {
                #[\Dagger\Attribute\DaggerFunction]
                #[\Dagger\Attribute\Agent]
                public function assist(LLM $base, #[\Dagger\Attribute\ListOfType('string')] array $names): LLM {return $base;}
            }))->getMethod('assist'),
            Invalid::agentRequiresArgument('assist', 'names'),
        ];

        yield 'agent that requires two bases' => [
            (new ReflectionClass(new #[DaggerObject] class () {
                #[\Dagger\Attribute\DaggerFunction]
                #[\Dagger\Attribute\Agent]
                public function assist(LLM $base, LLM $other): LLM {return $base;}
            }))->getMethod('assist'),
            Invalid::agentRequiresArgument('assist', 'other'),
        ];

        yield 'agent with an optional second LLM' => [
            (new ReflectionClass(new #[DaggerObject] class () {
                #[\Dagger\Attribute\DaggerFunction]
                #[\Dagger\Attribute\Agent]
                public function assist(LLM $base, ?LLM $other = null): LLM {return $base;}
            }))->getMethod('assist'),
            Invalid::agentTakesOneBase('assist'),
        ];

        yield 'agent without a base' => [
            (new ReflectionClass(new #[DaggerObject] class () {
                #[\Dagger\Attribute\DaggerFunction]
                #[\Dagger\Attribute\Agent]
                public function assist(): LLM {return new LLM();}
            }))->getMethod('assist'),
            Invalid::agentTakesOneBase('assist'),
        ];

        yield 'agent with an optional base' => [
            (new ReflectionClass(new #[DaggerObject] class () {
                #[\Dagger\Attribute\DaggerFunction]
                #[\Dagger\Attribute\Agent]
                public function assist(?LLM $base): LLM {return new LLM();}
            }))->getMethod('assist'),
            Invalid::agentTakesOneBase('assist'),
        ];

        yield 'agent that may return null' => [
            (new ReflectionClass(new #[DaggerObject] class () {
                #[\Dagger\Attribute\DaggerFunction]
                #[\Dagger\Attribute\Agent]
                public function assist(LLM $base): ?LLM {return null;}
            }))->getMethod('assist'),
            Invalid::agentReturnsLLM('assist', LLM::class),
        ];

        yield 'agent that returns a list' => [
            (new ReflectionClass(new #[DaggerObject] class () {
                #[\Dagger\Attribute\DaggerFunction]
                #[\Dagger\Attribute\Agent]
                #[\Dagger\Attribute\ReturnsListOfType('string')]
                public function assist(LLM $base): array {return [];}
            }))->getMethod('assist'),
            Invalid::agentReturnsLLM('assist', 'array'),
        ];
    }

    /** @return Generator<array{ 0: bool, 1:string }> */
    public static function provideNamesThatMayBeConstructors(): Generator
    {
        $cases = [
            [true, ''],
            [false, '__construct'],
            [false, '_construct'],
            [false, 'construct'],
            [false, '__toString'],
        ];

        foreach ($cases as [$isConstructor, $name]) {
            yield $name => [$isConstructor, $name];
        }
    }

    /** @return Generator<array{ 0: DaggerFunction, 1:ReflectionMethod}> */
    public static function provideReflectionMethods(): Generator
    {
        yield 'no parameters' => [
            new DaggerFunction(
                'returnBool',
                null,
                [],
                new Type('bool'),
            ),
            new ReflectionMethod(
                DaggerObjectWithDaggerFunctions::class,
                'returnBool',
            ),
        ];

        yield 'no parameters, method description' => [
            new DaggerFunction(
                'returnInt',
                'this method returns 1',
                [],
                new Type('int'),
            ),
            new ReflectionMethod(
                DaggerObjectWithDaggerFunctions::class,
                'returnInt',
            )
        ];

        yield 'one parameter' => [
            new DaggerFunction(
                'requiredString',
                null,
                [new Argument('value', '', new Type('string'))],
                new Type('void'),
            ),
            new ReflectionMethod(
                DaggerObjectWithDaggerFunctions::class,
                'requiredString',
            ),
        ];

        yield 'annotated parameter' => [
            new DaggerFunction(
                'annotatedString',
                null,
                [new Argument(
                    'value',
                    'this value should have a description',
                    new Type('string')
                )],
                new Type('void'),
            ),
            new ReflectionMethod(
                DaggerObjectWithDaggerFunctions::class,
                'annotatedString',
            ),
        ];

        yield 'implicitly optional parameter' => [
            new DaggerFunction(
                'implicitlyOptionalContainer',
                null,
                [new Argument(
                    'value',
                    '',
                    new Type(Container::class, true),
                    new Json('null'),
                )],
                new Type('void'),
            ),
            new ReflectionMethod(
                DaggerObjectWithDaggerFunctions::class,
                'implicitlyOptionalContainer',
            ),
        ];

        yield 'explicitly optional parameter' => [
            new DaggerFunction(
                'explicitlyOptionalFile',
                null,
                [new Argument(
                    'value',
                    '',
                    new Type(File::class, true),
                    new Json('null'),
                )],
                new Type('void'),
            ),
            new ReflectionMethod(
                DaggerObjectWithDaggerFunctions::class,
                'explicitlyOptionalFile',
            ),
        ];

        yield 'check that returns nothing' => [
            new DaggerFunction(
                name: 'withCheckVoid',
                description: null,
                arguments: [],
                returnType: new Type('void'),
                withCheck: true,
            ),
            new ReflectionMethod(
                DaggerObjectWithDaggerFunctions::class,
                'withCheckVoid',
            ),
        ];

        yield 'check that returns container' => [
            new DaggerFunction(
                name: 'withCheckContainer',
                description: null,
                arguments: [],
                returnType: new Type(Container::class),
                withCheck: true,
            ),
            new ReflectionMethod(
                DaggerObjectWithDaggerFunctions::class,
                'withCheckContainer',
            ),
        ];
    }
}
