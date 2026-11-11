<?php

declare(strict_types=1);

namespace Dagger\Tests\Unit\Codegen;

use Dagger\Client\ModuleTarget;
use Dagger\Codegen\Codegen;
use Dagger\Codegen\Introspection\IntrospectionSchema;
use Dagger\Connection;
use Dagger\Dagger;
use GraphQL\Client as GqlClient;
use GraphQL\Results;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

#[Group('unit')]
#[CoversClass(Codegen::class)]
class CodegenTest extends TestCase
{
    private const CLIENT_DIR = 'Clients/MyLinter';

    private const ADDRESS = 'git://example.com/my-linter.git@main';

    private const PIN = '0123456789abcdef0123456789abcdef01234567';

    private string $root;

    private string $otherRoot;

    protected function setUp(): void
    {
        $this->root = vfsStream::setup()->url() . '/generated';
        $this->otherRoot = dirname($this->root) . '/withoutClient';
    }

    #[Test]
    public function itLeavesClientTypesOutOfCore(): void
    {
        $this->generate(self::schema(), null);

        self::assertSame(['Client.php', 'Container.php', 'Id.php', 'NetworkProtocol.php'], $this->files(''));
        $client = $this->read('Client.php');
        self::assertStringContainsString('namespace Dagger;', $client);
        self::assertStringContainsString('function container(', $client);
        self::assertStringNotContainsString('MyLinter', $client);
    }

    #[Test]
    public function itGeneratesCoreAsIfTheSchemaHadNoClient(): void
    {
        $data = self::schemaData();
        $data['__schema']['types'] = array_values(array_filter(
            $data['__schema']['types'],
            static fn (array $type) => !isset($type['directives']),
        ));
        foreach ($data['__schema']['types'] as &$type) {
            $type['fields'] = array_values(array_filter(
                $type['fields'] ?? [],
                static fn (array $field) => !isset($field['directives']),
            ));
        }
        $io = new SymfonyStyle(new ArrayInput([]), new NullOutput());
        (new Codegen(IntrospectionSchema::fromArray($data), $this->otherRoot, $io))->generate();
        $this->generate(self::schema(), null);

        self::assertSame(scandir($this->otherRoot), scandir($this->root));
        foreach ($this->files('') as $file) {
            self::assertFileEquals("$this->otherRoot/$file", "$this->root/$file");
        }
    }

    #[Test]
    public function itGeneratesOnlyTheTargetIntoItsNamespace(): void
    {
        $this->generate(self::schema(), null);
        $this->generate(self::schema(), 'my-linter');

        self::assertSame(['Client.php', 'Clients', 'Container.php', 'Id.php', 'NetworkProtocol.php'], $this->files(''));
        self::assertSame(
            [
                '.client-module', 'Client.php', 'MyLinter.php', 'MyLinterLevel.php', 'MyLinterReport.php',
                'MyLinterRule.php', 'MyLinterRuleClient.php',
            ],
            $this->files(self::CLIENT_DIR),
        );
        foreach (preg_grep('/\.php$/', $this->files(self::CLIENT_DIR)) as $file) {
            $code = $this->read(self::CLIENT_DIR . "/$file");
            self::assertStringContainsString("namespace Dagger\\Clients\\MyLinter;", $code);
            token_get_all($code, TOKEN_PARSE);
        }

        $client = $this->read(self::CLIENT_DIR . '/Client.php');
        self::assertStringContainsString('final class Client' . "\n", $client);
        self::assertStringContainsString('public static function myLinter(?string $client = null): MyLinter', $client);
        self::assertStringContainsString('new \Dagger\Clients\MyLinter\MyLinter(', $client);
        self::assertStringNotContainsString('function container(', $client);
        self::assertStringNotContainsString('$this', $client);

        $linter = $this->read(self::CLIENT_DIR . '/MyLinter.php');
        self::assertStringContainsString('\Dagger\Container $source', $linter);
        self::assertStringContainsString('?MyLinterLevel $level', $linter);
        self::assertStringContainsString('new \Dagger\Clients\MyLinter\MyLinterReport(', $linter);
        self::assertStringContainsString('new \Dagger\Clients\MyLinter\MyLinterRuleClient(', $linter);

        $report = $this->read(self::CLIENT_DIR . '/MyLinterReport.php');
        self::assertStringContainsString('new \Dagger\Container(', $report);
        self::assertStringContainsString('\Dagger\Clients\MyLinter\MyLinterLevel::from(', $report);
    }

    #[Test]
    public function itRegeneratesAClient(): void
    {
        $this->generate(self::schema(), 'my-linter');
        $linter = $this->read(self::CLIENT_DIR . '/MyLinter.php');

        $this->generate(self::schema(), 'my-linter');

        self::assertSame(['MyLinter'], $this->files('Clients'));
        self::assertSame($linter, $this->read(self::CLIENT_DIR . '/MyLinter.php'));
    }

    #[Test]
    #[DataProvider('collidingModules')]
    public function itRefusesAClientWhoseNamespaceIsTaken(string $module, string $name): void
    {
        $this->generate(self::schema(), 'my-linter');

        $this->expectExceptionObject(new RuntimeException(
            "The client $module generates into Dagger\\Clients\\$name, "
            . 'and another client already generates into Dagger\Clients\MyLinter',
        ));
        try {
            $this->generate(self::schema(), $module);
        } finally {
            self::assertSame(['MyLinter'], $this->files('Clients'));
        }
    }

    public static function collidingModules(): iterable
    {
        yield 'equal' => ['my_linter', 'MyLinter'];
        yield 'equal ignoring case' => ['MYLINTER', 'MYLINTER'];
    }

    #[Test]
    public function itRefusesAFieldContributedToACoreTypeOtherThanQuery(): void
    {
        $data = self::schemaData();
        foreach ($data['__schema']['types'] as &$type) {
            if ($type['name'] === 'Container') {
                $type['fields'][] = [
                    'name' => 'lint',
                    'type' => ['kind' => 'OBJECT', 'name' => 'MyLinterReport'],
                    'directives' => [self::sourceMap('my-linter')],
                ];
            }
        }
        unset($type);

        $this->expectExceptionObject(new RuntimeException(
            'The client my-linter contributes Container.lint, and a client can only contribute fields to Query',
        ));
        try {
            $this->generate(IntrospectionSchema::fromArray($data), 'my-linter');
        } finally {
            self::assertDirectoryDoesNotExist($this->root);
        }
    }

    #[Test]
    public function itRefusesAClassNamePhpReserves(): void
    {
        $schema = IntrospectionSchema::fromArray(['__schema' => ['types' => [[
            'kind' => 'OBJECT',
            'name' => 'List',
            'fields' => [['name' => 'size', 'type' => ['kind' => 'SCALAR', 'name' => 'Int']]],
            'directives' => [self::sourceMap('list')],
        ]]]]);

        $this->expectExceptionObject(new RuntimeException(
            'The type List would generate the class List, which PHP reserves',
        ));
        $this->generate($schema, 'list');
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('pins')]
    public function twoEntryCallsServeTheTargetOnce(?string $pin, string $serve): void
    {
        [$queries, $linter] = $this->callEntryTwice($pin, failures: 0);

        self::assertCount(1, $queries);
        self::assertStringContainsString($serve, $queries[0]);
        self::assertStringContainsString(
            'myLinter(client: "mine")',
            (fn () => (string) $this->queryBuilderChain->getFullQuery())->call($linter),
        );
    }

    public static function pins(): iterable
    {
        yield 'pinned' => [self::PIN, 'serveModule(address: "' . self::ADDRESS . '" refPin: "' . self::PIN . '")'];
        yield 'unpinned' => [null, 'serveModule(address: "' . self::ADDRESS . '")'];
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function aFailedServeIsTriedAgain(): void
    {
        [$queries] = $this->callEntryTwice(self::PIN, failures: 1);

        self::assertCount(2, $queries);
        self::assertSame($queries[0], $queries[1]);
    }

    /**
     * Each caller runs in a process of its own: the generated classes and the shared client stay
     * defined for the rest of the process.
     *
     * @return array{string[], object} the queries two entry calls send, and the second call's result
     */
    private function callEntryTwice(?string $pin, int $failures): array
    {
        $this->generate(self::schema(), null);
        $this->generate(self::schema(), 'my-linter', new ModuleTarget(self::ADDRESS, $pin));
        // sdk-unit-check runs with a generated core client, sdk-codegen-unit-check without one.
        if (!class_exists(\Dagger\Client::class)) {
            require $this->root . '/Client.php';
        }
        require $this->root . '/' . self::CLIENT_DIR . '/Client.php';
        require $this->root . '/' . self::CLIENT_DIR . '/MyLinter.php';

        $queries = [];
        $graphQl = self::createStub(GqlClient::class);
        $graphQl->method('runQuery')->willReturnCallback(
            function ($query) use (&$queries, &$failures): Results {
                $queries[] = (string) $query->getQuery();
                if ($failures-- > 0) {
                    throw new RuntimeException('the engine refused to serve the module');
                }
                $results = self::createStub(Results::class);
                $results->method('getData')->willReturn(['serveModule' => null]);
                return $results;
            },
        );
        $connection = self::createStub(Connection::class);
        $connection->method('connect')->willReturn($graphQl);
        (new \ReflectionProperty(Dagger::class, 'client'))->setValue(null, new \Dagger\Client($connection));

        $linter = null;
        for ($call = 0; $call < 2; $call++) {
            try {
                $linter = \Dagger\Clients\MyLinter\Client::myLinter('mine');
            } catch (RuntimeException) {
            }
        }
        return [$queries, $linter];
    }

    private function generate(IntrospectionSchema $schema, ?string $module, ?ModuleTarget $target = null): void
    {
        $io = new SymfonyStyle(new ArrayInput([]), new NullOutput());
        $target ??= $module === null ? null : new ModuleTarget(self::ADDRESS, self::PIN);
        (new Codegen($schema, $this->root, $io, $module, $target))->generate();
    }

    /**
     * @return string[]
     */
    private function files(string $dir): array
    {
        return array_values(array_diff(scandir($this->root . '/' . $dir), ['.', '..']));
    }

    private function read(string $file): string
    {
        return file_get_contents($this->root . '/' . $file);
    }

    private static function schema(): IntrospectionSchema
    {
        return IntrospectionSchema::fromArray(self::schemaData());
    }

    private static function schemaData(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/Fixture/client-schema.json'), true);
    }

    private static function sourceMap(string $module): array
    {
        return ['name' => 'sourceMap', 'args' => [['name' => 'module', 'value' => json_encode($module)]]];
    }
}
