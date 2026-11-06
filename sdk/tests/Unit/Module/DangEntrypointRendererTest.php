<?php

declare(strict_types=1);

namespace Dagger\Tests\Unit\Module;

use Dagger\Module\DangEntrypointRenderer;
use Dagger\Module\Description;
use Dagger\Module\EngineNaming;
use Dagger\ValueObject\DaggerEnum;
use Dagger\ValueObject\DaggerEnumCase;
use Dagger\ValueObject\DaggerObject;
use Dagger\ValueObject\Type;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('unit')]
#[CoversClass(DangEntrypointRenderer::class)]
#[CoversClass(EngineNaming::class)]
class DangEntrypointRendererTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/Fixture';

    #[Test, DataProvider('provideTranslations')]
    public function itTranslatesMetadataToTypeDefs(string $expected): void
    {
        $types = DangEntrypointRenderer::renderTypes(Description::fromDirectory(self::FIXTURE), 'main');

        self::assertStringContainsString($expected, $types);
    }

    /** @return Generator<string, array{string}> */
    public static function provideTranslations(): Generator
    {
        yield 'object with an escaped description' => ['typeDef.withObject("Main", description: "The \"main\" object.")'];
        yield 'field' => ['.withField("greeting", typeDef.withKind(TypeDefKind.STRING_KIND), description: "What it says.")'];
        yield 'list field' => ['.withField("modes", typeDef.withListOf(typeDef.withEnum("Mode")))'];
        yield 'function' => ['function("greet", typeDef.withKind(TypeDefKind.STRING_KIND))'];
        yield 'function description' => ['.withDescription("Reads the state.")'];
        yield 'nullable return' => ['function("firstMode", typeDef.withOptional(true).withEnum("Mode"))'];
        yield 'own object return' => ['function("withMode", typeDef.withObject("Main"))'];
        yield 'void return' => ['function("nothing", typeDef.withKind(TypeDefKind.VOID_KIND))'];
        yield 'check' => ["function(\"lint\", typeDef.withKind(TypeDefKind.VOID_KIND))\n            .withCheck"];
        yield 'required argument' => ['.withArg("mode", typeDef.withEnum("Mode"))'];
        yield 'nullable argument has no default' => ['.withArg("value", typeDef.withOptional(true).withKind(TypeDefKind.STRING_KIND))'];
        yield 'string default' => ['.withArg("value", typeDef.withOptional(true).withKind(TypeDefKind.STRING_KIND), defaultValue: ("\"fallback\"" :: Dagger.JSON!))'];
        yield 'int default' => ['.withArg("number", typeDef.withKind(TypeDefKind.INTEGER_KIND), defaultValue: ("3" :: Dagger.JSON!))'];
        yield 'bool default' => ['.withArg("on", typeDef.withKind(TypeDefKind.BOOLEAN_KIND), defaultValue: ("true" :: Dagger.JSON!))'];
        yield 'empty list default' => ['.withArg("modes", typeDef.withListOf(typeDef.withEnum("Mode")), defaultValue: ("[]" :: Dagger.JSON!))'];
        yield 'nested list' => ['.withArg("rows", typeDef.withListOf(typeDef.withListOf(typeDef.withKind(TypeDefKind.STRING_KIND))))'];
        yield 'path and ignore' => ['.withArg("directory", typeDef.withObject("Directory"), defaultPath: "./src", ignore: ["vendor", "**/*.log"])'];
        yield 'scalar' => ['.withArg("platform", typeDef.withScalar("Platform"))'];
        yield 'scalar named differently in the schema' => ['.withArg("json", typeDef.withScalar("JSON"))'];
        yield 'idable object' => ['.withArg("base", typeDef.withObject("Container"))'];
        yield 'enum' => ['typeDef.withEnum("Mode", description: "How fast to go.")'];
        yield 'enum member' => ['.withEnumMember("Fast", value: "fast, really", description: "As fast as it can.")'];
        yield 'constructor' => [".withConstructor(\n          function(\"\", typeDef.withObject(\"Main\"))"];
    }

    #[Test]
    public function itOmitsWhatIsNotExposed(): void
    {
        $types = DangEntrypointRenderer::renderTypes(Description::fromDirectory(self::FIXTURE), 'main');

        self::assertStringNotContainsString('notExposed', $types);
        self::assertStringNotContainsString('"null"', $types);
    }

    #[Test]
    public function itWritesTheConstructorOfTheMainObjectOnly(): void
    {
        $description = Description::fromDirectory(self::FIXTURE);

        $asMain = DangEntrypointRenderer::renderTypes($description, 'main');
        self::assertSame(1, substr_count($asMain, '.withConstructor('));

        $asHelper = DangEntrypointRenderer::renderTypes($description, 'helper');
        self::assertSame(1, substr_count($asHelper, '.withConstructor('));
        self::assertStringContainsString('function("", typeDef.withObject("Helper"))', $asHelper);
    }

    #[Test]
    public function itGivesAMainObjectWithoutFieldsAConstructor(): void
    {
        $description = new Description([new DaggerObject(name: \DaggerModule\Helper::class)]);

        self::assertStringContainsString(
            'function("", typeDef.withObject("Helper"))',
            DangEntrypointRenderer::renderTypes($description, 'helper'),
        );
    }

    #[Test]
    public function itWritesStringsDangReadsBack(): void
    {
        $description = new Description([new DaggerObject(
            name: \DaggerModule\Helper::class,
            description: "says \"hi\"\nto \$you and \u{e9}\\",
        )]);

        self::assertStringContainsString(
            'description: "says \"hi\"\nto $you and é\\\\"',
            DangEntrypointRenderer::renderTypes($description, 'helper'),
        );
    }

    #[Test, DataProvider('provideCollidingCases')]
    public function itRefusesCasesTheEngineWouldMerge(string $first, string $second, string $name): void
    {
        self::expectException(RuntimeException::class);
        self::expectExceptionMessage(sprintf(
            'its cases %s and %s the same name, %s',
            addcslashes($first, "\x80..\xff"),
            addcslashes($second, "\x80..\xff"),
            $name,
        ));

        DangEntrypointRenderer::renderTypes(self::enumOf('Other', $first, $second), 'main');
    }

    /** @return Generator<string, array{string, string, string}> */
    public static function provideCollidingCases(): Generator
    {
        yield 'differing by case' => ['Low', 'LOW', 'LOW'];
        yield 'a word boundary and an underscore' => ['FooBar', 'FOO_BAR', 'FOO_BAR'];
        yield 'two spellings of a boundary' => ['FooBar', 'Foo_Bar', 'FOO_BAR'];
        yield 'a leading no-break space' => ["\u{00A0}LOW", 'LOW', 'LOW'];
        yield 'a trailing ideographic space' => ['Low', "Low\u{3000}", 'LOW'];
        yield 'a trimmed name reaching a boundary' => ["\u{00A0}A1", 'A_1', 'A_1'];
    }

    #[Test, DataProvider('provideDistinctCases')]
    public function itAcceptsCasesTheEngineKeepsApart(string ...$cases): void
    {
        $types = DangEntrypointRenderer::renderTypes(self::enumOf(...$cases), 'main');

        foreach ($cases as $case) {
            self::assertStringContainsString(sprintf('.withEnumMember("%s")', $case), $types);
        }
    }

    /** @return Generator<string, list<string>> */
    public static function provideDistinctCases(): Generator
    {
        yield 'ordinary cases' => ['Low', 'High'];
        yield 'with and without a boundary' => ['FOOBAR', 'FOO_BAR'];
        yield 'mixed and upper case' => ['FooBar', 'FOOBAR'];
        yield 'a word boundary only in one' => ['ReadOnly', 'READONLY'];
        yield 'a digit boundary only in one' => ['Foo1', 'FOO1'];
        yield 'one and two underscores' => ['Foo_Bar', 'Foo__Bar'];
        yield 'a leading underscore' => ['_Low', 'Low'];
        yield 'an underscore before a digit' => ['A1', 'A_1'];
        yield 'several' => ['Low', 'Medium', 'High'];
        yield 'a trimmed name that keeps its boundary' => ["\u{00A0}A1", 'A1'];
        yield 'a no-break space inside a name' => ["Lo\u{00A0}w", 'Low'];
        yield 'a zero width space, which is not trimmed' => ["\u{200B}LOW", 'LOW'];
    }

    #[Test]
    public function itRefusesCollidingCasesFoundInPhpSource(): void
    {
        $directory = sys_get_temp_dir() . '/' . uniqid('dagger-enum-', true);
        mkdir($directory);
        $file = "$directory/SpacedLevel.php";
        file_put_contents($file, <<<PHP
            <?php
            namespace DaggerModule;
            #[\\Dagger\\Attribute\\DaggerObject]
            enum SpacedLevel
            {
                case \u{00A0}LOW;
                case LOW;
            }
            PHP);
        require_once $file;

        try {
            $description = Description::fromDirectory($directory);
        } finally {
            unlink($file);
            rmdir($directory);
        }

        self::assertSame(["\u{00A0}LOW", 'LOW'], array_map(
            static fn($case) => $case->name,
            $description->enums[0]->cases,
        ));
        self::expectExceptionMessage('its cases \302\240LOW and LOW the same name, LOW');

        DangEntrypointRenderer::renderTypes($description, 'main');
    }

    private static function enumOf(string ...$cases): Description
    {
        return new Description([], [new DaggerEnum(
            'DaggerModule\\Mode',
            '',
            array_map(static fn($case) => new DaggerEnumCase($case, '', ''), $cases),
        )]);
    }

    #[Test]
    public function itRefusesCustomInterfaces(): void
    {
        $description = new Description([new DaggerObject(
            name: 'DaggerModule\\MyModule',
            fields: [new \Dagger\ValueObject\DaggerField('stream', null, new Type(\Stringable::class))],
        )]);

        self::expectException(RuntimeException::class);
        self::expectExceptionMessage('Currently cannot handle custom interfaces');

        DangEntrypointRenderer::renderTypes($description, 'my-module');
    }

    #[Test]
    public function itRendersTheCallContract(): void
    {
        $main = DangEntrypointRenderer::renderMain('v1.0.0-beta.21');

        self::assertStringStartsWith("# Code generated by dagger. DO NOT EDIT.\n", $main);
        self::assertStringContainsString('pub types(workspace: Workspace!): [TypeDef!]!', $main);
        self::assertStringContainsString('callDigest: String,', $main);
        self::assertStringContainsString('.withEnvVariable("DAGGER_CLIENT_VERSION", "v1.0.0-beta.21")', $main);
        self::assertStringContainsString('.withEnvVariable("DAGGER_MODULE_CALL_DIGEST", callDigest ?? "")', $main);
        self::assertStringContainsString('"sdk/bin/dagger-module", "call"', $main);
        self::assertStringContainsString('experimentalPrivilegedNesting: true', $main);
        self::assertStringContainsString('PhpModuleBuild(source: currentModule.source).installed', $main);
    }

    #[Test]
    public function itWritesTheSameFilesTwice(): void
    {
        $output = sys_get_temp_dir() . '/' . uniqid('dagger-entrypoint-', true);
        $description = Description::fromDirectory(self::FIXTURE);

        DangEntrypointRenderer::write($description, 'main', 'v1.0.0-beta.21', $output);
        $first = [file_get_contents("$output/main.dang"), file_get_contents("$output/types.dang")];
        DangEntrypointRenderer::write($description, 'main', 'v1.0.0-beta.21', $output);
        $second = [file_get_contents("$output/main.dang"), file_get_contents("$output/types.dang")];

        unlink("$output/main.dang");
        unlink("$output/types.dang");
        rmdir($output);

        self::assertSame($first, $second);
    }
}
