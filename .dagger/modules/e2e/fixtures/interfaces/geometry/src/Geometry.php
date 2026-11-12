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

    #[Attribute\DaggerFunction]
    public function larger(Shape $first, Shape $second): Shape
    {
        return $first->area(1) >= $second->area(1) ? $first : $second;
    }

    /**
     * @param list<Shape> $shapes
     * @return list<Shape>
     */
    #[Attribute\DaggerFunction]
    #[Attribute\ReturnsListOfType(Shape::class)]
    public function sorted(#[Attribute\ListOfType(Shape::class)] array $shapes): array
    {
        usort($shapes, static fn(Shape $a, Shape $b) => $a->area(1) <=> $b->area(1));

        return $shapes;
    }
}
