<?php

declare(strict_types=1);

namespace Dagger\Tests\Unit\Module\Unsupported;

use Dagger\Attribute;

#[Attribute\DaggerObject]
interface ReservedConstant
{
    final public const GRAPHQL_TYPE = 'Other';

    #[Attribute\DaggerFunction]
    public function name(): string;
}
