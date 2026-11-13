<?php

declare(strict_types=1);

namespace DaggerModule;

use Dagger\Attribute;

#[Attribute\DaggerObject]
interface Shape
{
    #[Attribute\DaggerFunction]
    public function name(): string;

    #[Attribute\DaggerFunction]
    public function area(int $scale): int;
}
