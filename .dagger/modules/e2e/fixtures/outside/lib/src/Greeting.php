<?php

declare(strict_types=1);

namespace Acme\Lib;

final class Greeting
{
    public static function text(): string
    {
        return 'hello from lib';
    }
}
