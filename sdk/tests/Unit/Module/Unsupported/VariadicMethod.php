<?php

declare(strict_types=1);

namespace Dagger\Tests\Unit\Module\Unsupported;

use Dagger\Attribute;

#[Attribute\DaggerObject]
interface VariadicMethod
{
    #[Attribute\DaggerFunction]
    public function name(int ...$scales): string;
}
