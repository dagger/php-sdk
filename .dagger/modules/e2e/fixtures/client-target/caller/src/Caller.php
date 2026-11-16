<?php

declare(strict_types=1);

namespace DaggerModule;

use Dagger\Attribute;
use Dagger\Clients\FileLister\Client as FileLister;
use Dagger\Clients\FileMaker\Client as FileMaker;

#[Attribute\DaggerObject]
class Caller
{
    #[Attribute\DaggerFunction]
    public function listMade(): string
    {
        return FileLister::fileLister()->entries(FileMaker::fileMaker()->writeHtml(['a', 'b']));
    }
}
