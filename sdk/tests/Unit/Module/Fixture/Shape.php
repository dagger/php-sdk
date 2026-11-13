<?php

declare(strict_types=1);

namespace DaggerModule;

use Dagger\Attribute;
use Dagger\Container;
use Dagger\Directory;

#[Attribute\DaggerObject]
#[Attribute\Doc('Something with an area.')]
interface Shape
{
    #[Attribute\DaggerFunction]
    public function area(int $scale = 1): int;

    #[Attribute\DaggerFunction]
    public function base(Directory $source): Container;

    #[Attribute\DaggerFunction]
    public function label(string $queryBuilder): string;
}
