<?php

declare(strict_types=1);

namespace DaggerModule;

use Acme\Lib\Greeting;
use Dagger\Attribute\DaggerFunction;
use Dagger\Attribute\DaggerObject;
use Shared\Punctuation;

#[DaggerObject]
class OutsideApp
{
    #[DaggerFunction]
    public function greet(): string
    {
        return Greeting::text() . Punctuation::MARK;
    }

    #[DaggerFunction]
    public function extra(): string
    {
        return trim((string) file_get_contents(dirname(__DIR__, 2) . '/extra/note.txt'));
    }
}
