<?php

declare(strict_types=1);

namespace Dagger\Tests\Unit\Client;

use Dagger\Client;
use Dagger\Client\AbstractClient;
use Dagger\Connection;
use GraphQL\Client as GqlClient;
use GraphQL\Results;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('unit')]
#[CoversClass(AbstractClient::class)]
class AbstractClientTest extends TestCase
{
    private const ADDRESS = 'git://example.com/my-linter.git@main';

    private const PIN = '0123456789abcdef0123456789abcdef01234567';

    /** @var list<string> */
    private array $queries = [];

    #[Test]
    public function itServesATargetOncePerSession(): void
    {
        $client = $this->client(failures: 0);

        $client->serve(self::ADDRESS, self::PIN);
        $client->serve(self::ADDRESS, self::PIN);
        $client->serve(self::ADDRESS);

        self::assertCount(2, $this->queries);
        self::assertStringContainsString(
            'serveModule(address: "' . self::ADDRESS . '" refPin: "' . self::PIN . '")',
            $this->queries[0],
        );
        self::assertStringContainsString('serveModule(address: "' . self::ADDRESS . '")', $this->queries[1]);
    }

    #[Test]
    public function itServesAgainAfterAFailure(): void
    {
        $client = $this->client(failures: 1);

        try {
            $client->serve(self::ADDRESS, self::PIN);
            self::fail('the failed serve did not throw');
        } catch (RuntimeException) {
        }
        $client->serve(self::ADDRESS, self::PIN);
        $client->serve(self::ADDRESS, self::PIN);

        self::assertCount(2, $this->queries);
        self::assertSame($this->queries[0], $this->queries[1]);
    }

    private function client(int $failures): Client
    {
        $graphQl = self::createStub(GqlClient::class);
        $graphQl->method('runQuery')->willReturnCallback(function ($query) use (&$failures): Results {
            $this->queries[] = (string) $query->getQuery();
            if ($failures-- > 0) {
                throw new RuntimeException('the engine refused to serve the module');
            }
            $results = self::createStub(Results::class);
            $results->method('getData')->willReturn(['serveModule' => null]);

            return $results;
        });
        $connection = self::createStub(Connection::class);
        $connection->method('connect')->willReturn($graphQl);

        return new Client($connection);
    }
}
