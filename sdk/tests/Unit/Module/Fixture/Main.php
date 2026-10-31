<?php

declare(strict_types=1);

namespace DaggerModule;

use Dagger\Attribute;
use Dagger\Container;
use Dagger\Directory;
use Dagger\Json;
use Dagger\Platform;
use RuntimeException;

#[Attribute\DaggerObject]
#[Attribute\Doc('The "main" object.')]
class Main
{
    #[Attribute\DaggerFunction]
    #[Attribute\Doc('What it says.')]
    public string $greeting;

    /** @var list<Mode> */
    #[Attribute\DaggerFunction]
    #[Attribute\ListOfType(Mode::class)]
    public array $modes;

    public ?Directory $source;

    public ?Helper $helper = null;

    #[Attribute\DaggerFunction]
    public function __construct(
        string $greeting = 'hello',
        #[Attribute\ListOfType(Mode::class)]
        array $modes = [],
        ?Directory $source = null,
    ) {
        $this->greeting = $greeting;
        $this->modes = $modes;
        $this->source = $source;
    }

    #[Attribute\DaggerFunction]
    #[Attribute\Doc('Reads the state.')]
    public function greet(): string
    {
        return $this->greeting;
    }

    #[Attribute\DaggerFunction]
    public function firstMode(): ?Mode
    {
        return $this->modes[0] ?? null;
    }

    #[Attribute\DaggerFunction]
    public function withMode(Mode $mode): Main
    {
        $this->modes[] = $mode;

        return $this;
    }

    #[Attribute\DaggerFunction]
    public function sourceId(): string
    {
        return (string) $this->source->id();
    }

    #[Attribute\DaggerFunction]
    public function required(string $value): string
    {
        return $value;
    }

    #[Attribute\DaggerFunction]
    public function nullable(?string $value): string
    {
        return $value ?? 'was null';
    }

    #[Attribute\DaggerFunction]
    public function defaulted(?string $value = 'fallback'): string
    {
        return $value ?? 'was null';
    }

    #[Attribute\DaggerFunction]
    #[Attribute\ReturnsListOfType(new Attribute\ListOfType('string'))]
    public function rows(
        #[Attribute\ListOfType(new Attribute\ListOfType('string'))]
        array $rows,
    ): array {
        return $rows;
    }

    #[Attribute\DaggerFunction]
    #[Attribute\ReturnsListOfType('string')]
    public function gaps(): array
    {
        return [1 => 'one', 3 => 'three'];
    }

    #[Attribute\DaggerFunction]
    public function directoryId(
        #[Attribute\DefaultPath('./src')]
        #[Attribute\Ignore('vendor', '**/*.log')]
        Directory $directory,
    ): string {
        return (string) $directory->id();
    }

    #[Attribute\DaggerFunction]
    public function scalars(Platform $platform, Json $json): string
    {
        return $platform . ' ' . $json;
    }

    #[Attribute\DaggerFunction]
    public function count(int $number = 3, float $ratio = 0.5, bool $on = true): string
    {
        return sprintf('%d %.1f %s', $number, $ratio, $on ? 'on' : 'off');
    }

    #[Attribute\DaggerFunction]
    public function nothing(): void
    {
    }

    #[Attribute\DaggerFunction]
    #[Attribute\Check]
    public function lint(): void
    {
    }

    #[Attribute\DaggerFunction]
    public function fail(): string
    {
        throw new RuntimeException('the function failed');
    }

    public function notExposed(): string
    {
        return 'hidden';
    }

    #[Attribute\DaggerFunction]
    public function container(Container $base): Container
    {
        return $base;
    }
}
