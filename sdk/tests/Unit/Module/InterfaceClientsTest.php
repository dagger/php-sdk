<?php

declare(strict_types=1);

namespace Dagger\Tests\Unit\Module;

use Dagger\Client;
use Dagger\GraphQl\QueryBuilderChain;
use Dagger\Id;
use Dagger\Module\Description;
use Dagger\Module\InterfaceClients;
use Dagger\Module\ValueCodec;
use Dagger\ModuleNaming;
use Dagger\Tests\Unit\Module\Unsupported;
use Dagger\ValueObject\DaggerFunction;
use Dagger\ValueObject\DaggerObject;
use Dagger\ValueObject\Type;
use DaggerModule\Mode;
use DaggerModule\Shape;
use Generator;
use Nette\PhpGenerator\PsrPrinter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

#[Group('unit')]
#[CoversClass(InterfaceClients::class)]
#[CoversClass(ValueCodec::class)]
class InterfaceClientsTest extends TestCase
{
    private function naming(): ModuleNaming
    {
        $naming = self::createStub(ModuleNaming::class);
        $naming->method('typeNames')->willReturnCallback(
            static fn(array $names) => array_map(static fn($name) => "Main$name", $names),
        );
        $naming->method('fieldNames')->willReturnCallback(
            static fn(array $names) => array_map(static fn($name) => "{$name}Field", $names),
        );

        return $naming;
    }

    private static function render(string $interface, ModuleNaming $naming): string
    {
        return (new PsrPrinter())->printClass(
            InterfaceClients::render(DaggerObject::fromReflection(new ReflectionClass($interface)), $naming),
        );
    }

    #[Test]
    public function itQueriesTheInterfaceByTheEngineNames(): void
    {
        $printed = self::render(Shape::class, $this->naming());

        self::assertStringContainsString("public const GRAPHQL_TYPE = 'MainShape';", $printed);
        self::assertStringContainsString('public function area(int $scale = 1): int', $printed);
        self::assertStringContainsString(
            "return \$this->queryLeaf((new \\Dagger\\Client\\QueryBuilder('areaField'))"
                . "->setArgument('scaleField', \$scale), 'areaField');",
            $printed,
        );
        self::assertStringContainsString(
            "return new \\Dagger\\Container(\$this->client, \$this->queryBuilderChain->chain("
                . "(new \\Dagger\\Client\\QueryBuilder('baseField'))->setArgument('sourceField', \$source)));",
            $printed,
        );
    }

    #[Test]
    public function itDeclaresNoVariableAnArgumentCouldShadow(): void
    {
        $printed = self::render(Shape::class, $this->naming());

        self::assertStringContainsString("->setArgument('queryBuilderField', \$queryBuilder)", $printed);
        self::assertDoesNotMatchRegularExpression('/^\s*\$\w+ =/m', $printed);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function itLoadsAnInterfaceArgumentByItsId(): void
    {
        $directory = sys_get_temp_dir() . '/' . uniqid('interfaces-');
        InterfaceClients::write(Description::fromDirectory(__DIR__ . '/Fixture'), $directory, $this->naming());
        require "$directory/Shape.php";

        $client = self::createStub(Client::class);
        $client->method('loadObjectFromId')->willReturnCallback(
            static function (string $class, Id $id, ?string $typeName) use ($client) {
                self::assertSame(['the-id', 'MainShape'], [(string) $id, $typeName]);

                return new $class($client, new QueryBuilderChain());
            },
        );

        self::assertInstanceOf(Shape::class, ValueCodec::forClient($client)->decode('the-id', new Type(Shape::class)));
    }

    #[Test]
    public function itRefusesATargetWithoutTheNamingApi(): void
    {
        self::expectException(RuntimeException::class);
        self::expectExceptionMessage('needs a target from v1.0.0-beta.26');

        InterfaceClients::write(Description::fromDirectory(__DIR__ . '/Fixture'), sys_get_temp_dir(), null);
    }

    #[Test, DataProvider('provideUnimplementable')]
    public function itRefusesMethodsItCannotImplement(string $interface, string $problem): void
    {
        self::expectException(RuntimeException::class);
        self::expectExceptionMessage("The method $interface::name of a module interface $problem");

        self::render($interface, $this->naming());
    }

    /** @return Generator<string, array{string, string}> */
    public static function provideUnimplementable(): Generator
    {
        yield 'unattributed' => [Unsupported\UnattributedMethod::class, 'has no #[DaggerFunction]'];
        yield 'static' => [Unsupported\StaticMethod::class, 'is static'];
        yield 'variadic' => [Unsupported\VariadicMethod::class, 'is variadic'];
    }

    #[Test]
    public function itRefusesItsOwnConstant(): void
    {
        self::expectException(RuntimeException::class);
        self::expectExceptionMessage(
            'The module interface Dagger\\Tests\\Unit\\Module\\Unsupported\\ReservedConstant has the constant'
                . ' GRAPHQL_TYPE, which its generated class declares',
        );

        self::render(Unsupported\ReservedConstant::class, $this->naming());
    }

    #[Test, DataProvider('provideReserved')]
    public function itRefusesMethodsNamedLikeItsOwn(string $interface, string $method): void
    {
        self::expectException(RuntimeException::class);
        self::expectExceptionMessage(
            "The method $interface::$method of a module interface has a name its generated class uses",
        );

        self::render($interface, $this->naming());
    }

    /** @return Generator<string, array{string, string}> */
    public static function provideReserved(): Generator
    {
        yield 'AbstractObject method' => [Unsupported\ReservedMethod::class, 'queryLeaf'];
        yield 'id' => [Unsupported\IdMethod::class, 'id'];
    }

    #[Test]
    public function itRefusesTypesItCannotQuery(): void
    {
        $interface = new DaggerObject(Shape::class, functions: [
            new DaggerFunction('area', null, [], new Type(Mode::class)),
        ]);

        self::expectException(RuntimeException::class);
        self::expectExceptionMessage(
            'The interface function DaggerModule\\Shape::area cannot take or return DaggerModule\\Mode yet',
        );

        InterfaceClients::render($interface, $this->naming());
    }
}
