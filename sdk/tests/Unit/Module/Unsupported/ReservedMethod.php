<?php

declare(strict_types=1);

namespace Dagger\Tests\Unit\Module\Unsupported;

use Dagger\Attribute;

#[Attribute\DaggerObject]
interface ReservedMethod
{
    #[Attribute\DaggerFunction]
    public function queryLeaf(): string;
}
