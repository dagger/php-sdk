<?php

declare(strict_types=1);

namespace DaggerModule;

use Dagger\Attribute;

#[Attribute\DaggerObject]
class Geometry
{
    #[Attribute\DaggerFunction]
    public function describe(Shape $shape): string
    {
        return $shape->name() . ' ' . $shape->area(2);
    }

    /** @param list<Shape> $shapes */
    #[Attribute\DaggerFunction]
    public function names(#[Attribute\ListOfType(Shape::class)] array $shapes): string
    {
        return implode(',', array_map(static fn(Shape $shape) => $shape->name(), $shapes));
    }
}
