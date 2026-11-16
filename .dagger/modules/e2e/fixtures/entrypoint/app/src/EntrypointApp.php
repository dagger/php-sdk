<?php

declare(strict_types=1);

namespace DaggerModule;

use Dagger\Attribute;
use Dagger\Changeset;
use Dagger\Directory;
use Dagger\LLM;
use Dagger\Service;
use Dagger\Workspace;
use RuntimeException;

use function Dagger\dag;

#[Attribute\DaggerObject]
class EntrypointApp
{
    #[Attribute\DaggerFunction]
    public string $greeting;

    /** @var list<Level> */
    #[Attribute\DaggerFunction]
    #[Attribute\ListOfType(Level::class)]
    public array $levels;

    #[Attribute\DaggerFunction]
    public function __construct(
        string $greeting = 'hello',
        #[Attribute\ListOfType(Level::class)]
        array $levels = [],
    ) {
        $this->greeting = $greeting;
        $this->levels = $levels;
    }

    #[Attribute\DaggerFunction]
    public function greet(?string $name = 'world', ?string $suffix = null): string
    {
        return $this->greeting . ' ' . ($name ?? 'nobody') . ($suffix ?? '');
    }

    #[Attribute\DaggerFunction]
    public function withLevel(Level $level): EntrypointApp
    {
        $this->levels[] = $level;

        return $this;
    }

    #[Attribute\DaggerFunction]
    public function lastLevel(): ?Level
    {
        return $this->levels === [] ? null : $this->levels[array_key_last($this->levels)];
    }

    #[Attribute\DaggerFunction]
    #[Attribute\ReturnsListOfType(new Attribute\ListOfType('string'))]
    public function grid(): array
    {
        return [['a,b', 'c'], []];
    }

    #[Attribute\DaggerFunction]
    #[Attribute\ReturnsListOfType('string')]
    public function commas(): array
    {
        return ['a,b', 'c'];
    }

    #[Attribute\DaggerFunction]
    #[Attribute\ReturnsListOfType('string')]
    public function nothing(): array
    {
        return [];
    }

    #[Attribute\DaggerFunction]
    #[Attribute\ReturnsListOfType('string')]
    public function entries(Directory $dir): array
    {
        return $dir->entries();
    }

    #[Attribute\DaggerFunction]
    public function hasFile(string $path): bool
    {
        return file_exists(dirname(__DIR__) . '/' . $path);
    }

    #[Attribute\DaggerFunction]
    public function fail(): string
    {
        throw new RuntimeException('the fixture failed on purpose');
    }

    #[Attribute\DaggerFunction]
    #[Attribute\Check]
    public function selfCheck(Workspace $ws): void
    {
    }

    #[Attribute\DaggerFunction]
    #[Attribute\Generate]
    public function selfGenerate(): Changeset
    {
        return dag()->directory()->withNewFile('generated.txt', 'generated')->changes(dag()->directory());
    }

    #[Attribute\DaggerFunction]
    #[Attribute\Up]
    public function selfUp(): Service
    {
        return dag()->container()->from('alpine')->asService();
    }

    #[Attribute\DaggerFunction]
    #[Attribute\Agent]
    public function selfAgent(LLM $base): LLM
    {
        return $base;
    }
}
