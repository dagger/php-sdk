<?php

declare(strict_types=1);

namespace DaggerModule;

use Dagger\Attribute;

#[Attribute\DaggerObject]
#[Attribute\Doc('How fast to go.')]
enum Mode: string
{
    #[Attribute\Doc('As fast as it can.')]
    case Fast = 'fast, really';

    case Slow = 'slow';
}
