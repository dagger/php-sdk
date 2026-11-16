<?php

declare(strict_types=1);

namespace Dagger\Tests\Unit\Module\Unsupported;

use Dagger\Attribute;

#[Attribute\DaggerObject]
interface IdMethod
{
    #[Attribute\DaggerFunction]
    public function id(): string;
}
