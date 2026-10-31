<?php

declare(strict_types=1);

namespace DaggerModule;

use Dagger\Attribute;

#[Attribute\DaggerObject]
enum Level: string
{
    case Low = 'a low level';
    case High = 'a high level';
}
