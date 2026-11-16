<?php

declare(strict_types=1);

namespace Dagger\Tests\Unit\Module\Unsupported;

use Dagger\Attribute;

#[Attribute\DaggerObject]
interface StaticMethod
{
    #[Attribute\DaggerFunction]
    public static function name(): string;
}
