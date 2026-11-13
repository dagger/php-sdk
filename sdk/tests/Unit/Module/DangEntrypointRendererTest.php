<?php

declare(strict_types=1);

namespace Dagger\Tests\Unit\Module;

use Dagger\Module\DangEntrypointRenderer;
use Dagger\Module\Description;
use Dagger\ModuleNaming;
use Dagger\ValueObject\DaggerEnum;
use Dagger\ValueObject\DaggerEnumCase;
use Dagger\ValueObject\DaggerObject;
use Dagger\ValueObject\ListOfType;
use Dagger\ValueObject\Type;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('unit')]
#[CoversClass(DangEntrypointRenderer::class)]
class DangEntrypointRendererTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/Fixture';

    /**
     * Stands in for ModuleNaming: the main object is the candidate named after
     * the module in any case, and an enum member is its case in upper case.
     */
    private function naming(string $module = 'main'): ModuleNaming
    {
        $naming = self::createStub(ModuleNaming::class);
        $naming->method('mainObject')->willReturnCallback(static fn(array $candidates) => array_values(array_filter(
            $candidates,
            static fn($name) => strcasecmp($name, $module) === 0,
        ))[0] ?? null);
        $naming->method('enumMemberNames')->willReturnCallback(
            static fn(array $names) => array_map(strtoupper(...), $names),
        );

        return $naming;
    }

    #[Test, DataProvider('provideTranslations')]
    public function itTranslatesMetadataToTypeDefs(string $expected): void
    {
        $types = DangEntrypointRenderer::renderTypes(Description::fromDirectory(self::FIXTURE), $this->naming());

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
        yield 'check with workspace' => ["  .withCheck\n            .withArg(\"ws\", typeDef.withObject(\"Workspace\"))"];
        yield 'generator' => ["function(\"generate\", typeDef.withObject(\"Changeset\"))\n            .withGenerator"];
        yield 'up' => ["function(\"serve\", typeDef.withObject(\"Service\"))\n            .withUp"];
        yield 'agent' => ["function(\"assist\", typeDef.withObject(\"LLM\"))\n            .withAgent"];
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
        yield 'interface' => ['typeDef.withInterface("Shape", description: "Something with an area.")'];
        yield 'interface function' => ['.withArg("scale", typeDef.withKind(TypeDefKind.INTEGER_KIND), defaultValue: ("1" :: Dagger.JSON!))'];
        yield 'interface argument' => ['.withArg("shape", typeDef.withInterface("Shape"))'];
        yield 'interface list return' => ['function("shapes", typeDef.withListOf(typeDef.withInterface("Shape")))'];
        yield 'constructor' => [".withConstructor(\n          function(\"\", typeDef.withObject(\"Main\"))"];
    }

    #[Test]
    public function itOmitsWhatIsNotExposed(): void
    {
        $types = DangEntrypointRenderer::renderTypes(Description::fromDirectory(self::FIXTURE), $this->naming());

        self::assertStringNotContainsString('notExposed', $types);
        self::assertStringNotContainsString('"null"', $types);
    }

    #[Test]
    public function itWritesTheConstructorOfTheMainObjectOnly(): void
    {
        $description = Description::fromDirectory(self::FIXTURE);

        $asMain = DangEntrypointRenderer::renderTypes($description, $this->naming());
        self::assertSame(1, substr_count($asMain, '.withConstructor('));

        $asHelper = DangEntrypointRenderer::renderTypes($description, $this->naming('helper'));
        self::assertSame(1, substr_count($asHelper, '.withConstructor('));
        self::assertStringContainsString('function("", typeDef.withObject("Helper"))', $asHelper);
    }

    #[Test]
    public function itWritesNoConstructorWhenTheEngineFindsNoMainObject(): void
    {
        $types = DangEntrypointRenderer::renderTypes(Description::fromDirectory(self::FIXTURE), $this->naming('other'));

        self::assertStringNotContainsString('.withConstructor(', $types);
    }

    #[Test]
    public function itRethrowsAnotherTypeError(): void
    {
        $naming = self::createStub(ModuleNaming::class);
        $naming->method('mainObject')->willThrowException(new \TypeError('something else'));

        self::expectExceptionObject(new \TypeError('something else'));

        DangEntrypointRenderer::renderTypes(Description::fromDirectory(self::FIXTURE), $naming);
    }

    #[Test]
    public function itGivesAMainObjectWithoutFieldsAConstructor(): void
    {
        $description = new Description([new DaggerObject(name: \DaggerModule\Helper::class)]);

        self::assertStringContainsString(
            'function("", typeDef.withObject("Helper"))',
            DangEntrypointRenderer::renderTypes($description, $this->naming('helper')),
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
            DangEntrypointRenderer::renderTypes($description, $this->naming('helper')),
        );
    }

    #[Test]
    public function itRefusesCasesTheEngineNamesAlike(): void
    {
        self::expectException(RuntimeException::class);
        self::expectExceptionMessage('its cases Low and LOW the same name, LOW');

        DangEntrypointRenderer::renderTypes(self::enumOf('Low', 'LOW'), $this->naming());
    }

    #[Test]
    public function itAcceptsCasesTheEngineNamesApart(): void
    {
        $types = DangEntrypointRenderer::renderTypes(self::enumOf('Low', 'High'), $this->naming());

        self::assertStringContainsString('.withEnumMember("Low")', $types);
        self::assertStringContainsString('.withEnumMember("High")', $types);
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

        DangEntrypointRenderer::renderTypes($description, $this->naming());
    }

    #[Test]
    public function itRefusesInterfaceFields(): void
    {
        $description = new Description([new DaggerObject(
            name: 'DaggerModule\\MyModule',
            fields: [new \Dagger\ValueObject\DaggerField('shape', null, new Type(\DaggerModule\Shape::class))],
        )]);

        self::expectException(RuntimeException::class);
        self::expectExceptionMessage('Currently cannot handle custom interfaces: DaggerModule\\Shape');

        DangEntrypointRenderer::renderTypes($description, $this->naming());
    }

    #[Test]
    #[RunInSeparateProcess]
    public function itRefusesClientTypes(): void
    {
        class_alias(\stdClass::class, 'Dagger\\Clients\\FileLister\\FileLister');
        $description = new Description([new DaggerObject(
            name: 'DaggerModule\\MyModule',
            fields: [new \Dagger\ValueObject\DaggerField('lister', null, new ListOfType(new Type('Dagger\\Clients\\FileLister\\FileLister')))],
        )]);

        self::expectException(RuntimeException::class);
        self::expectExceptionMessage('The type Dagger\\Clients\\FileLister\\FileLister comes from the module client FileLister');

        DangEntrypointRenderer::renderTypes($description, $this->naming());
    }

    #[Test]
    public function itRendersTheCallContract(): void
    {
        $main = DangEntrypointRenderer::renderMain('v1.0.0-beta.21');

        self::assertStringStartsWith("# Code generated by dagger. DO NOT EDIT.\n", $main);
        self::assertStringContainsString(
            "pub sources(workspace: Workspace!): Directory! {\n"
                . "    directory.withDirectory(workspace.cwd, workspace.directory(\".\", exclude: [\"**/.git\", \"vendor\"], gitignore: true))\n"
                . "  }",
            $main,
        );
        self::assertStringContainsString('pub types: [TypeDef!]!', $main);
        self::assertStringNotContainsString('workspace: Workspace!,', $main);
        self::assertStringContainsString('callDigest: String,', $main);
        self::assertStringContainsString('.withEnvVariable("DAGGER_CLIENT_VERSION", "v1.0.0-beta.21")', $main);
        self::assertStringContainsString('.withEnvVariable("DAGGER_MODULE_CALL_DIGEST", callDigest ?? "")', $main);
        self::assertStringContainsString('"sdk/bin/dagger-module", "call"', $main);
        self::assertStringContainsString('experimentalPrivilegedNesting: true)', $main);
        self::assertStringContainsString('.file("/dagger/result.json")', $main);
        self::assertStringContainsString('.contents rescue {', $main);
        self::assertStringContainsString('err: GraphQLError =>', $main);
        self::assertStringContainsString('{ raise err }', $main);
        self::assertStringContainsString('raise stderr', $main);
        self::assertStringContainsString(
            'PhpModuleBuild(contextDir: currentModule.contextDirectory, subPath: currentModule.sourceSubpath).installed',
            $main,
        );
    }

    #[Test]
    public function itWritesTheSameFilesTwice(): void
    {
        $output = sys_get_temp_dir() . '/' . uniqid('dagger-entrypoint-', true);
        $description = Description::fromDirectory(self::FIXTURE);

        DangEntrypointRenderer::write($description, $this->naming(), 'v1.0.0-beta.21', $output);
        $first = [file_get_contents("$output/main.dang"), file_get_contents("$output/types.dang")];
        DangEntrypointRenderer::write($description, $this->naming(), 'v1.0.0-beta.21', $output);
        $second = [file_get_contents("$output/main.dang"), file_get_contents("$output/types.dang")];

        unlink("$output/main.dang");
        unlink("$output/types.dang");
        rmdir($output);

        self::assertSame($first, $second);
    }
}
