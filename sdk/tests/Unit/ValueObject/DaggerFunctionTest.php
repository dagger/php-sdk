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
use Dagger\ValueObject\ListOfType;
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
    private const AGENT_REQUIRES = 'Agents may only require their base LLM, a Workspace or an Agent';

    #[Test]
    #[DataProvider('provideInvalidDaggerFunctions')]
    public function itCannotTakeInvalidDaggerFunctions(
        ReflectionMethod $reflection,
        \RuntimeException $expected,
    ): void {
        self::expectExceptionObject($expected);

        DaggerFunction::fromReflection($reflection);
    }

    #[Test, DataProvider('provideRoles')]
    public function itLetsARoleRequireAWorkspace(
        string $role,
        Type $returns,
        string $label,
        string $requires,
        string $mustReturn,
    ): void {
        $workspace = new Argument('workspace', '', new Type(Workspace::class));

        self::assertTrue((new DaggerFunction('f', null, [$workspace], $returns, ...[$role => true]))->$role);
    }

    #[Test, DataProvider('provideRoles')]
    public function itRefusesARoleThatRequiresAnotherArgument(
        string $role,
        Type $returns,
        string $label,
        string $requires,
        string $mustReturn,
    ): void {
        foreach ([new Type('string'), new ListOfType(new Type('string'))] as $type) {
            try {
                new DaggerFunction('f', null, [new Argument('name', '', $type)], $returns, ...[$role => true]);
                self::fail("$role accepted a required argument");
            } catch (Invalid $e) {
                self::assertEquals(Invalid::requires('f', $label, 'name', $requires), $e);
            }
        }
    }

    #[Test, DataProvider('provideRoles')]
    public function itRefusesARoleThatReturnsAList(
        string $role,
        Type $returns,
        string $label,
        string $requires,
        string $mustReturn,
    ): void {
        self::expectExceptionObject(Invalid::returns('f', $label, 'array', $mustReturn));

        new DaggerFunction('f', null, [], new ListOfType($returns), ...[$role => true]);
    }

    #[Test, DataProvider('provideRoles')]
    public function itRefusesANullableReturnUnlessACheck(
        string $role,
        Type $returns,
        string $label,
        string $requires,
        string $mustReturn,
    ): void {
        if ($role !== 'withCheck') {
            self::expectExceptionObject(Invalid::returns('f', $label, $returns->name, $mustReturn));
        }

        $function = new DaggerFunction('f', null, [], new Type($returns->name, nullable: true), ...[$role => true]);

        self::assertTrue($function->$role);
    }

    /** @return Generator<string, array{string, Type, string, string, string}> */
    public static function provideRoles(): Generator
    {
        yield 'check' => [
            'withCheck',
            new Type(Container::class),
            'a check',
            'Checks may only require a Workspace',
            'Checks must return Containers or void',
        ];
        yield 'generator' => [
            'withGenerator',
            new Type(Changeset::class),
            'a generator',
            'Generators may only require a Workspace',
            'Generators must return a non-null Changeset',
        ];
        yield 'up' => [
            'withUp',
            new Type(Service::class),
            'up',
            'Up functions may only require a Workspace',
            'Up functions must return a non-null Service',
        ];
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

        yield 'agent that requires an argument' => [
            (new ReflectionClass(new #[DaggerObject] class () {
                #[\Dagger\Attribute\DaggerFunction]
                #[\Dagger\Attribute\Agent]
                public function assist(LLM $base, string $name): LLM {return $base;}
            }))->getMethod('assist'),
            Invalid::requires('assist', 'an agent', 'name', self::AGENT_REQUIRES),
        ];

        yield 'agent that requires a list' => [
            (new ReflectionClass(new #[DaggerObject] class () {
                #[\Dagger\Attribute\DaggerFunction]
                #[\Dagger\Attribute\Agent]
                public function assist(LLM $base, #[\Dagger\Attribute\ListOfType('string')] array $names): LLM {return $base;}
            }))->getMethod('assist'),
            Invalid::requires('assist', 'an agent', 'names', self::AGENT_REQUIRES),
        ];

        yield 'agent that requires two bases' => [
            (new ReflectionClass(new #[DaggerObject] class () {
                #[\Dagger\Attribute\DaggerFunction]
                #[\Dagger\Attribute\Agent]
                public function assist(LLM $base, LLM $other): LLM {return $base;}
            }))->getMethod('assist'),
            Invalid::requires('assist', 'an agent', 'other', self::AGENT_REQUIRES),
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
            Invalid::returns('assist', 'an agent', LLM::class, 'Agents must return a non-null LLM'),
        ];

        yield 'agent that returns a list' => [
            (new ReflectionClass(new #[DaggerObject] class () {
                #[\Dagger\Attribute\DaggerFunction]
                #[\Dagger\Attribute\Agent]
                #[\Dagger\Attribute\ReturnsListOfType('string')]
                public function assist(LLM $base): array {return [];}
            }))->getMethod('assist'),
            Invalid::returns('assist', 'an agent', 'array', 'Agents must return a non-null LLM'),
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
