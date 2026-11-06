<?php

declare(strict_types=1);

namespace DaggerModule;

use Dagger\Attribute;

#[Attribute\DaggerObject]
class Helper
{
    #[Attribute\DaggerFunction]
    public string $label;

    /** @var list<Mode> */
    #[Attribute\ListOfType(Mode::class)]
    public array $modes = [];

    #[Attribute\DaggerFunction]
    public function __construct(string $label = 'help')
    {
        $this->label = $label;
    }

    #[Attribute\DaggerFunction]
    public function shout(): string
    {
        return $this->label;
    }
}
