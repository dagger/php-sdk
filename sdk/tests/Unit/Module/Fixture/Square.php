<?php

declare(strict_types=1);

namespace DaggerModule;

use Dagger\Container;
use Dagger\Directory;

use function Dagger\core;

final class Square implements Shape
{
    public function area(int $scale = 1): int
    {
        return $scale;
    }

    public function base(Directory $source): Container
    {
        return core()->container()->withDirectory('/src', $source);
    }

    public function label(string $queryBuilder): string
    {
        return $queryBuilder;
    }
}
